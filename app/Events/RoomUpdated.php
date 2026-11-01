<?php

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells every device in a room that its state changed. Deliberately carries no room state:
 * clients re-fetch their own view over HTTP, so per-player hidden information never travels
 * over a shared channel.
 */
class RoomUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $code,
        public array $meta = [],
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('room.'.$this->code);
    }

    public function broadcastAs(): string
    {
        return 'room.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->meta;
    }
}
