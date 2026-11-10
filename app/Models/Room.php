<?php

namespace App\Models;

use App\Enums\RoomStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['code', 'host_user_id', 'capacity', 'game_key', 'mission', 'last_captain_seat', 'status', 'started_at'])]
class Room extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RoomStatus::class,
            'capacity' => 'integer',
            'mission' => 'integer',
            'last_captain_seat' => 'integer',
            'started_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    /**
     * @return HasMany<RoomPlayer, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(RoomPlayer::class)->orderBy('seat');
    }

    /**
     * @return HasOne<Game, $this>
     */
    public function game(): HasOne
    {
        return $this->hasOne(Game::class);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * Codes are stored upper-case; accept whatever case was typed into the address bar.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->where($field ?? 'code', strtoupper((string) $value))->first();
    }

    public function playerFor(User $user): ?RoomPlayer
    {
        return $this->players->firstWhere('user_id', $user->id);
    }

    public function isHost(User $user): bool
    {
        return $this->host_user_id === $user->id;
    }

    public function isFull(): bool
    {
        return $this->players->count() >= $this->capacity;
    }

    public function broadcastChannelName(): string
    {
        return 'room.'.$this->code;
    }
}
