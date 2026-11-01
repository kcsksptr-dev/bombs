<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends a web push to all of a player's devices, in the player's language. Queued so that a slow
 * push service never holds up a move. Does nothing when no VAPID keys are configured.
 */
class SendPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(
        public int $userId,
        public string $key,
        public array $replace,
        public string $url,
    ) {}

    public function handle(): void
    {
        $config = config('bombbusters.push');
        $user = User::find($this->userId);

        if (! $user || ! $config['public_key'] || ! $config['private_key']) {
            return;
        }

        $subscriptions = PushSubscription::where('user_id', $user->id)->get();
        $payload = json_encode([
            'title' => config('app.name'),
            'body' => __($this->key, $this->replace, $user->locale),
            'url' => $this->url,
            'tag' => $this->url,
        ]);

        $push = new WebPush(['VAPID' => [
            'subject' => $config['subject'],
            'publicKey' => $config['public_key'],
            'privateKey' => $config['private_key'],
        ]], ['TTL' => $config['ttl'], 'urgency' => 'high']);

        foreach ($subscriptions as $subscription) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => 'aes128gcm', // what Safari requires; the library defaults to the older aesgcm
            ]), $payload);
        }

        foreach ($push->flush() as $report) {
            // The player turned notifications off or the browser dropped the address
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            }
        }
    }
}
