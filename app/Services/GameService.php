<?php

namespace App\Services;

use App\Enums\RoomStatus;
use App\Events\RoomUpdated;
use App\Games\GameRegistry;
use App\Games\GameRuleException;
use App\Jobs\SendPush;
use App\Models\Game;
use App\Models\Room;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies moves to a room's stored game, whatever the game. Same pattern as LobbyService: lock the
 * game row, run the game's pure engine, save, then tell the room something changed (no state in the
 * broadcast). Game-specific parts go through the room's GameDefinition.
 */
class GameService
{
    public function __construct(
        private readonly GameRegistry $games,
    ) {}

    /**
     * Deal a fresh game for the room's current seating and settings. Called inside the lobby's
     * start transaction.
     */
    public function create(Room $room): Game
    {
        $order = $room->players->sortBy('seat')->pluck('user_id')->values()->all();

        $game = $room->game()->updateOrCreate([], [
            'state' => $this->games->for($room)->deal($room, $order),
        ]);
        $game->snapshots()->delete();

        return $game;
    }

    /**
     * Runs one move of the room's game. $game guards against a request for another game's
     * endpoint (an old tab after the room switched games).
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $move
     */
    public function move(Room $room, string $game, Closure $move): void
    {
        if ($room->game_key !== $game) {
            throw ValidationException::withMessages(['game' => __('game.errors.no_game')]);
        }

        $definition = $this->games->for($room);

        [$before, $after] = DB::transaction(function () use ($room, $move, $definition) {
            $stored = $room->game()->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['game' => __('game.errors.no_game')]);

            try {
                $before = $stored->state;
                $next = $move($before);

                $stored->snapshots()->create(['state' => $before]);
                $stored->update(['state' => $next]);
                $definition->afterMove($before, $stored->state);

                return [$before, $stored->state];
            } catch (GameRuleException $exception) {
                // Kinds like 'yellow' in the message get the player's word for them
                $replace = array_map(fn ($value) => is_string($value) ? __("game.kind.{$value}") : $value, $exception->replace);

                throw ValidationException::withMessages(['game' => __($exception->key, $replace)]);
            }
        });

        rescue(fn () => broadcast(new RoomUpdated($room->code)));
        $this->pushIfWaiting($room, $before, $after);
    }

    /**
     * Host takes back the latest move, including one that ended the game. One step per call.
     */
    public function undo(Room $room, User $user): void
    {
        if (! $room->isHost($user)) {
            throw ValidationException::withMessages(['game' => __('lobby.errors.host_only')]);
        }

        $definition = $this->games->for($room);

        [$before, $after] = DB::transaction(function () use ($room, $user, $definition) {
            $game = $room->game()->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['game' => __('game.errors.no_game')]);

            $snapshot = $game->snapshots()->latest('id')->first()
                ?? throw ValidationException::withMessages(['game' => __('game.errors.nothing_to_undo')]);

            $before = $game->state;
            $definition->beforeUndo($before, $snapshot);
            $game->update(['state' => $definition->markUndone($snapshot->state, $user->id)]);
            $snapshot->delete();

            return [$before, $game->state];
        });

        rescue(fn () => broadcast(new RoomUpdated($room->code)));
        $this->pushIfWaiting($room, $before, $after);
    }

    /**
     * One player calls another back to the table: a push for a sleeping phone, and the broadcast
     * lets an awake one show it (on the LAN that's all there is, since push needs HTTPS).
     */
    public function nudge(Room $room, User $user, User $target): void
    {
        $state = $room->game?->state;
        $order = $state['order'] ?? [];

        if (! in_array($user->id, $order, true) || ! in_array($target->id, $order, true) || $user->is($target)) {
            throw ValidationException::withMessages(['game' => __('game.errors.nudge_invalid')]);
        }

        if (! Cache::add("nudge:{$room->id}:{$user->id}:{$target->id}", true, config('bombbusters.push.nudge_cooldown'))) {
            throw ValidationException::withMessages(['game' => __('game.errors.nudge_cooldown')]);
        }

        $this->push($room, $target->id, 'push.nudge', ['name' => $user->name]);
        rescue(fn () => broadcast(new RoomUpdated($room->code, ['nudged_user_id' => $target->id, 'nudged_by' => $user->id])));
    }

    /**
     * Host ends the game (finished or abandoned) and takes everyone back to the lobby. $advance asks
     * the game to move on (Bomb Busters: the next mission, after a win).
     */
    public function returnToLobby(Room $room, User $user, bool $advance = false): void
    {
        if (! $room->isHost($user)) {
            throw ValidationException::withMessages(['game' => __('lobby.errors.host_only')]);
        }

        DB::transaction(function () use ($room, $advance) {
            $changes = $room->game_key ? $this->games->for($room)->backToLobby($room, $room->game?->state, $advance) : [];

            $room->game()->delete();
            $room->players()->update(['is_ready' => false]);
            $room->update($changes + [
                'status' => RoomStatus::Lobby,
                'started_at' => null,
            ]);
        });

        rescue(fn () => broadcast(new RoomUpdated($room->code)));
    }

    /**
     * Pushes to the player the game now waits for, when that just changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function pushIfWaiting(Room $room, array $before, array $after): void
    {
        $definition = $this->games->for($room);
        $now = $definition->waitingFor($after);

        if ($now !== null && $now[0] !== ($definition->waitingFor($before)[0] ?? null)) {
            $this->push($room, $now[0], $now[1]);
        }
    }

    /**
     * Like broadcasts, a push hiccup must not fail the change itself.
     *
     * @param  array<string, string>  $replace
     */
    private function push(Room $room, int $userId, string $key, array $replace = []): void
    {
        rescue(fn () => SendPush::dispatch($userId, $key, $replace + ['room' => $room->code], "/rooms/{$room->code}/game"));
    }
}
