<?php

namespace App\Models;

use App\Enums\RoomStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A passwordless guest player, remembered on their device via the remember-me cookie.
 */
#[Fillable(['name', 'locale'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /**
     * @return HasMany<RoomPlayer, $this>
     */
    public function roomPlayers(): HasMany
    {
        return $this->hasMany(RoomPlayer::class);
    }

    /**
     * @return HasMany<MissionCompletion, $this>
     */
    public function missionCompletions(): HasMany
    {
        return $this->hasMany(MissionCompletion::class);
    }

    /**
     * Mission numbers this player has helped win.
     *
     * @return list<int>
     */
    public function completedMissions(): array
    {
        return $this->missionCompletions()->orderBy('mission')->pluck('mission')->all();
    }

    /**
     * The most recent room this player is still part of (lobby or running game).
     */
    public function activeRoom(): ?Room
    {
        return Room::query()
            ->whereIn('status', [RoomStatus::Lobby, RoomStatus::Playing])
            ->whereHas('players', fn ($query) => $query->where('user_id', $this->id))
            ->latest('updated_at')
            ->first();
    }
}
