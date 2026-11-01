<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Everyone seated in a room shares its presence channel; the member list drives the online indicators
Broadcast::channel('room.{code}', function (User $user, string $code) {
    $isMember = Room::where('code', $code)
        ->whereHas('players', fn ($query) => $query->where('user_id', $user->id))
        ->exists();

    return $isMember ? ['id' => $user->id, 'name' => $user->name] : false;
});
