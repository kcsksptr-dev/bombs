<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:512', PushSubscription::ENDPOINT_RULE],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        PushSubscription::updateOrCreate(['endpoint' => $validated['endpoint']], [
            'user_id' => $user->id,
            'public_key' => $validated['keys']['p256dh'],
            'auth_token' => $validated['keys']['auth'],
        ]);

        PushSubscription::where('user_id', $user->id)
            ->whereNotIn('id', PushSubscription::where('user_id', $user->id)->latest('id')->limit(PushSubscription::MAX_PER_USER)->pluck('id'))
            ->delete();

        return response()->noContent();
    }
}
