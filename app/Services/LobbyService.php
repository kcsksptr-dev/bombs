<?php

namespace App\Services;

use App\Enums\RoomStatus;
use App\Events\RoomUpdated;
use App\Games\GameRegistry;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All lobby state changes go through here. Each mutation locks the room row so concurrent taps from
 * several phones (two players grabbing the same seat, host starting while someone leaves) serialize,
 * then notifies every device in the room once the transaction has committed.
 */
class LobbyService
{
    public function __construct(
        private readonly GameService $games,
        private readonly GameRegistry $registry,
    ) {}

    /**
     * A new room starts on the game picker ($game null) unless a game is given.
     */
    public function create(User $host, int $capacity, int $mission = 1, ?string $game = null): Room
    {
        $this->assertValidCapacity($capacity);
        $this->assertValidMission($mission);

        if ($game !== null && ! $this->registry->has($game)) {
            $this->fail('lobby.errors.invalid_game');
        }

        return DB::transaction(function () use ($host, $capacity, $mission, $game) {
            $room = Room::create([
                'code' => $this->generateCode(),
                'host_user_id' => $host->id,
                'capacity' => $capacity,
                'game_key' => $game,
                'mission' => $mission,
                'status' => RoomStatus::Lobby,
            ]);

            $room->players()->create(['user_id' => $host->id, 'seat' => 1]);

            return $room;
        });
    }

    public function join(Room $room, User $user): void
    {
        $this->mutate($room, function (Room $room) use ($user) {
            if ($room->playerFor($user)) {
                return;
            }

            $this->assertInLobby($room);

            if ($room->isFull()) {
                $this->fail('lobby.errors.room_full');
            }

            $taken = $room->players->pluck('seat')->all();
            $seat = collect(range(1, $room->capacity))->first(fn (int $seat) => ! in_array($seat, $taken, true));

            $room->players()->create(['user_id' => $user->id, 'seat' => $seat]);
        });
    }

    public function takeSeat(Room $room, User $user, int $seat): void
    {
        $this->mutate($room, function (Room $room) use ($user, $seat) {
            $player = $this->member($room, $user);
            $this->assertInLobby($room);

            if ($seat < 1 || $seat > $room->capacity) {
                $this->fail('lobby.errors.invalid_seat');
            }

            if ($room->players->contains(fn (RoomPlayer $other) => $other->seat === $seat && $other->isNot($player))) {
                $this->fail('lobby.errors.seat_taken');
            }

            $player->update(['seat' => $seat]);
        });
    }

    public function setReady(Room $room, User $user, bool $ready): void
    {
        $this->mutate($room, function (Room $room) use ($user, $ready) {
            $this->assertInLobby($room);
            $this->member($room, $user)->update(['is_ready' => $ready]);
        });
    }

    public function leave(Room $room, User $user): void
    {
        $this->mutate($room, function (Room $room) use ($user) {
            $player = $room->playerFor($user);

            if (! $player) {
                return;
            }

            $this->assertInLobby($room);
            $this->removePlayer($room, $player);
        });
    }

    public function kick(Room $room, User $host, User $target): void
    {
        $this->mutate($room, function (Room $room) use ($host, $target) {
            $this->assertHost($room, $host);
            $this->assertInLobby($room);

            if ($host->is($target)) {
                $this->fail('lobby.errors.cannot_kick_self');
            }

            $this->removePlayer($room, $this->member($room, $target));
        }, ['kicked_user_id' => $target->id]);
    }

    public function transferHost(Room $room, User $host, User $target): void
    {
        $this->mutate($room, function (Room $room) use ($host, $target) {
            $this->assertHost($room, $host);
            $this->member($room, $target);

            $room->update(['host_user_id' => $target->id]);
        });
    }

    public function setCapacity(Room $room, User $host, int $capacity): void
    {
        $this->assertValidCapacity($capacity);

        $this->mutate($room, function (Room $room) use ($host, $capacity) {
            $this->assertHost($room, $host);
            $this->assertInLobby($room);

            if ($capacity < $room->players->count()) {
                $this->fail('lobby.errors.capacity_below_players');
            }

            if ($room->game_key) {
                $game = $this->registry->get($room->game_key);

                if ($capacity < $game->minPlayers() || $capacity > $game->maxPlayers()) {
                    $this->fail('lobby.errors.invalid_capacity', ['min' => $game->minPlayers(), 'max' => $game->maxPlayers()]);
                }
            }

            $this->resize($room, $capacity);
        });
    }

    /**
     * Shrinking the table pulls players sitting beyond the new capacity into the free seats.
     */
    private function resize(Room $room, int $capacity): void
    {
        $free = collect(range(1, $capacity))->diff($room->players->pluck('seat'))->values();

        foreach ($room->players->where('seat', '>', $capacity)->values() as $index => $player) {
            $player->update(['seat' => $free[$index]]);
        }

        $room->update(['capacity' => $capacity]);
    }

    /**
     * Host picks the game (null goes back to the picker). The table size is pulled into the
     * game's player range; a table already too big for the game can't pick it.
     */
    public function chooseGame(Room $room, User $host, ?string $game): void
    {
        $definition = $game === null ? null : $this->registry->get($game);

        $this->mutate($room, function (Room $room) use ($host, $game, $definition) {
            $this->assertHost($room, $host);
            $this->assertInLobby($room);

            if (! $definition) {
                $room->update(['game_key' => null]);

                return;
            }

            if ($room->players->count() > $definition->maxPlayers()) {
                $this->fail('lobby.errors.too_many_for_game', ['max' => $definition->maxPlayers()]);
            }

            $capacity = min(max($room->capacity, $definition->minPlayers()), $definition->maxPlayers());
            $this->resize($room, $capacity);
            $room->update(['game_key' => $game] + $definition->chosen($room, $host));
        });
    }

    public function setMission(Room $room, User $host, int $mission): void
    {
        $this->assertValidMission($mission);

        $this->mutate($room, function (Room $room) use ($host, $mission) {
            $this->assertHost($room, $host);
            $this->assertInLobby($room);

            $room->update(['mission' => $mission]);
        });
    }

    public function start(Room $room, User $host): void
    {
        $this->mutate($room, function (Room $room) use ($host) {
            $this->assertHost($room, $host);
            $this->assertInLobby($room);

            $game = $this->registry->for($room);

            if ($room->players->count() < $game->minPlayers()) {
                $this->fail('lobby.errors.not_enough_players', ['min' => $game->minPlayers()]);
            }

            if ($room->players->contains(fn (RoomPlayer $player) => ! $player->is_ready && $player->user_id !== $room->host_user_id)) {
                $this->fail('lobby.errors.not_everyone_ready');
            }

            $room->update(['status' => RoomStatus::Playing, 'started_at' => now()]);
            $this->games->create($room);
        });
    }

    /**
     * Run a change against a locked, freshly loaded room and broadcast it after commit.
     *
     * @param  Closure(Room): void  $change
     * @param  array<string, mixed>  $broadcast  extra payload for clients (never hidden game data)
     */
    private function mutate(Room $room, Closure $change, array $broadcast = []): void
    {
        $code = $room->code;

        DB::transaction(function () use ($room, $change) {
            $locked = Room::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('players');

            $change($locked);
        });

        // A Reverb hiccup must not fail the change itself; clients resync when their socket reconnects
        rescue(fn () => broadcast(new RoomUpdated($code, $broadcast)));
    }

    private function removePlayer(Room $room, RoomPlayer $player): void
    {
        $player->delete();
        $remaining = $room->players->reject(fn (RoomPlayer $other) => $other->is($player));

        if ($remaining->isEmpty()) {
            $room->update(['status' => RoomStatus::Finished]);

            return;
        }

        if ($room->host_user_id === $player->user_id) {
            $room->update(['host_user_id' => $remaining->sortBy('seat')->first()->user_id]);
        }
    }

    private function member(Room $room, User $user): RoomPlayer
    {
        return $room->playerFor($user) ?? $this->fail('lobby.errors.not_in_room');
    }

    private function assertHost(Room $room, User $user): void
    {
        if (! $room->isHost($user)) {
            $this->fail('lobby.errors.host_only');
        }
    }

    private function assertInLobby(Room $room): void
    {
        if ($room->status !== RoomStatus::Lobby) {
            $this->fail('lobby.errors.game_started');
        }
    }

    private function assertValidCapacity(int $capacity): void
    {
        if ($capacity < config('games.min_players') || $capacity > config('games.max_players')) {
            $this->fail('lobby.errors.invalid_capacity', [
                'min' => config('games.min_players'),
                'max' => config('games.max_players'),
            ]);
        }
    }

    private function assertValidMission(int $mission): void
    {
        if (config("missions.{$mission}") === null) {
            $this->fail('lobby.errors.invalid_mission');
        }
    }

    private function generateCode(): string
    {
        $alphabet = config('bombbusters.room_code_alphabet');
        $length = config('bombbusters.room_code_length');

        do {
            $code = collect(range(1, $length))
                ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->implode('');
        } while (Room::where('code', $code)->exists());

        return $code;
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function fail(string $key, array $replace = []): never
    {
        throw ValidationException::withMessages(['lobby' => __($key, $replace)]);
    }
}
