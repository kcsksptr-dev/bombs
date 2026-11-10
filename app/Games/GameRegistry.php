<?php

namespace App\Games;

use App\Models\Room;
use Illuminate\Validation\ValidationException;

/**
 * The games a room can play (config/games.php), resolved from the container.
 */
class GameRegistry
{
    /**
     * @return array<string, GameDefinition>
     */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), config('games.available'));
    }

    public function has(?string $key): bool
    {
        return $key !== null && array_key_exists($key, config('games.available'));
    }

    public function get(string $key): GameDefinition
    {
        $class = config("games.available.{$key}")
            ?? throw ValidationException::withMessages(['lobby' => __('lobby.errors.invalid_game')]);

        return app($class);
    }

    public function for(Room $room): GameDefinition
    {
        return $room->game_key
            ? $this->get($room->game_key)
            : throw ValidationException::withMessages(['lobby' => __('lobby.errors.choose_game')]);
    }

    /**
     * What the picker shows for each game.
     *
     * @return list<array{key: string, min: int, max: int}>
     */
    public function options(): array
    {
        return array_values(array_map(fn (GameDefinition $game) => [
            'key' => $game->key(),
            'min' => $game->minPlayers(),
            'max' => $game->maxPlayers(),
        ], $this->all()));
    }
}
