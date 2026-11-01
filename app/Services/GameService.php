<?php

namespace App\Services;

use App\Enums\RoomStatus;
use App\Events\RoomUpdated;
use App\Game\GameEngine;
use App\Game\GameRuleException;
use App\Jobs\SendPush;
use App\Models\Game;
use App\Models\GameSnapshot;
use App\Models\MissionCompletion;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies engine moves to a room's stored game. Same pattern as LobbyService: lock the game row,
 * run the pure engine, save, then tell the room something changed (no state in the broadcast).
 */
class GameService
{
    public function __construct(
        private readonly GameEngine $engine,
    ) {}

    /**
     * Deal a fresh game for the room's current seating and mission. Called inside the lobby's
     * start transaction.
     */
    public function create(Room $room): Game
    {
        $players = $room->players->sortBy('seat');
        $order = $players->pluck('user_id')->all();

        $game = $room->game()->updateOrCreate([], [
            'state' => $this->engine->start($order, $this->nextCaptain($room, $players), $room->mission),
        ]);
        $game->snapshots()->delete();

        return $game;
    }

    public function placeInfoToken(Room $room, User $user, int $index): void
    {
        $this->apply($room, fn (array $state) => $this->engine->placeInfoToken($state, $user->id, $index));
    }

    public function dualCut(Room $room, User $user, int $target, int $index, int|string $kind): void
    {
        $this->apply($room, fn (array $state) => $this->engine->dualCut($state, $user->id, $target, $index, $kind));
    }

    public function soloCut(Room $room, User $user, int|string $kind): void
    {
        $this->apply($room, fn (array $state) => $this->engine->soloCut($state, $user->id, $kind));
    }

    /**
     * @param  array{int, int}  $indices
     */
    public function doubleDetector(Room $room, User $user, int $target, array $indices, int|string $kind): void
    {
        $this->apply($room, fn (array $state) => $this->engine->doubleDetector($state, $user->id, $target, $indices, $kind));
    }

    public function revealForDetector(Room $room, User $user, int $index): void
    {
        $this->apply($room, fn (array $state) => $this->engine->revealForDetector($state, $user->id, $index));
    }

    public function pass(Room $room, User $user): void
    {
        $this->apply($room, fn (array $state) => $this->engine->pass($state, $user->id));
    }

    public function revealRed(Room $room, User $user): void
    {
        $this->apply($room, fn (array $state) => $this->engine->revealRed($state, $user->id));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function useEquipment(Room $room, User $user, string $id, array $params): void
    {
        $this->apply($room, fn (array $state) => $this->engine->useEquipment($state, $user->id, $id, $params));
    }

    /**
     * Host takes back the latest move, including one that won or lost the mission. One step per
     * call, so a Double Detector miss and the teammate's reveal are two steps.
     */
    public function undo(Room $room, User $user): void
    {
        if (! $room->isHost($user)) {
            throw ValidationException::withMessages(['game' => __('lobby.errors.host_only')]);
        }

        [$before, $after] = DB::transaction(function () use ($room, $user) {
            $game = $room->game()->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['game' => __('game.errors.no_game')]);

            $snapshot = $game->snapshots()->latest('id')->first()
                ?? throw ValidationException::withMessages(['game' => __('game.errors.nothing_to_undo')]);

            if ($game->state['phase'] === 'won') {
                $this->forgetCompletion($game->state, $snapshot);
            }

            $before = $game->state;
            $game->update(['state' => $this->engine->markUndone($snapshot->state, $user->id)]);
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
     * Host ends the mission (finished or abandoned) and takes everyone back to the lobby.
     */
    public function returnToLobby(Room $room, User $user, bool $nextMission = false): void
    {
        if (! $room->isHost($user)) {
            throw ValidationException::withMessages(['game' => __('lobby.errors.host_only')]);
        }

        DB::transaction(function () use ($room, $nextMission) {
            $state = $room->game?->state;
            $won = ($state['phase'] ?? null) === 'won';
            $next = $room->mission + 1;
            $captainSeat = $room->players()->where('user_id', $state['captain'] ?? null)->value('seat');

            $room->game()->delete();
            $room->players()->update(['is_ready' => false]);
            $room->update([
                'status' => RoomStatus::Lobby,
                'started_at' => null,
                'last_captain_seat' => $captainSeat ?? $room->last_captain_seat,
                // Only a won mission moves the table on, and only if there is a next one
                'mission' => $nextMission && $won && config("missions.{$next}") ? $next : $room->mission,
            ]);
        });

        rescue(fn () => broadcast(new RoomUpdated($room->code)));
    }

    /**
     * The host captains a room's first game; after that it passes to the next seated player,
     * however the previous game ended.
     *
     * @param  Collection<int, RoomPlayer>  $players  sorted by seat
     */
    private function nextCaptain(Room $room, Collection $players): int
    {
        if ($room->last_captain_seat === null) {
            return $room->host_user_id;
        }

        $next = $players->first(fn (RoomPlayer $player) => $player->seat > $room->last_captain_seat) ?? $players->first();

        return $next->user_id;
    }

    /**
     * Everyone who played a won mission gets it ticked off in their campaign.
     *
     * @param  array<string, mixed>  $state
     */
    private function recordCompletion(array $state): void
    {
        foreach ($state['order'] as $userId) {
            MissionCompletion::firstOrCreate(
                ['user_id' => $userId, 'mission' => $state['mission']],
                ['completed_at' => now()],
            );
        }
    }

    /**
     * Takes back what the undone winning move recorded. Anyone who won this mission in an earlier
     * game keeps their tick: their completion is older than the move.
     *
     * @param  array<string, mixed>  $state
     */
    private function forgetCompletion(array $state, GameSnapshot $winningMove): void
    {
        MissionCompletion::whereIn('user_id', $state['order'])
            ->where('mission', $state['mission'])
            ->where('completed_at', '>=', $winningMove->created_at)
            ->delete();
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $move
     */
    private function apply(Room $room, Closure $move): void
    {
        [$before, $after] = DB::transaction(function () use ($room, $move) {
            $game = $room->game()->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['game' => __('game.errors.no_game')]);

            try {
                $before = $game->state;
                $next = $move($before);

                $game->snapshots()->create(['state' => $before]);
                $game->update(['state' => $next]);

                if ($before['phase'] !== 'won' && $game->state['phase'] === 'won') {
                    $this->recordCompletion($game->state);
                }

                return [$before, $game->state];
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
     * Pushes to the player the game now waits for, when that just changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function pushIfWaiting(Room $room, array $before, array $after): void
    {
        $waitingFor = $this->waitingFor($after);

        if ($waitingFor !== null && $waitingFor !== $this->waitingFor($before)) {
            $this->push($room, $waitingFor, ($after['pending'] ?? null) ? 'push.reveal' : 'push.turn');
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function waitingFor(array $state): ?int
    {
        return $state['pending']['player'] ?? ($state['phase'] === 'playing' ? $state['turn'] : null);
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
