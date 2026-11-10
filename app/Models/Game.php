<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Persists the engine state (see App\Games\BombBusters\GameEngine). The state holds every player's hidden
 * wires, so it is hidden from serialization; clients only ever get App\Games\BombBusters\GameView output.
 */
#[Fillable(['room_id', 'state'])]
#[Hidden(['state'])]
class Game extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<GameSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(GameSnapshot::class);
    }
}
