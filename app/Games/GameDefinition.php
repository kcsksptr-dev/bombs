<?php

namespace App\Games;

use App\Models\GameSnapshot;
use App\Models\Room;
use App\Models\User;

/**
 * What the platform (rooms, lobby, GameService, undo, push) needs from a game. The rules themselves
 * stay in each game's pure engine; a definition is the thin adapter between that engine and the room.
 *
 * Every state must carry 'phase' and 'order' (user ids in seat order): nudges and the prune command
 * read them, whatever the game.
 */
interface GameDefinition
{
    /** The key stored in rooms.game_key, also used for translation keys (games.{key}.*) */
    public function key(): string;

    public function minPlayers(): int;

    public function maxPlayers(): int;

    /** Inertia page that plays a running game */
    public function page(): string;

    /**
     * A fresh game for the room's current seating.
     *
     * @param  list<int>  $order  user ids in seat order
     * @return array<string, mixed>
     */
    public function deal(Room $room, array $order): array;

    /**
     * The only way state reaches a client: strip whatever this viewer may not see.
     *
     * @param  array<string, mixed>  $state
     * @param  array<int, array{name: string, seat: int}>  $players
     * @return array<string, mixed>
     */
    public function view(array $state, int $viewer, array $players, int $undoSteps): array;

    /**
     * Who the game now waits for, and the push text key to send them.
     *
     * @param  array<string, mixed>  $state
     * @return array{int, string}|null
     */
    public function waitingFor(array $state): ?array;

    /**
     * Runs inside a move's transaction, after the new state is saved (e.g. campaign records).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function afterMove(array $before, array $after): void;

    /**
     * Runs inside the undo transaction, before $undone is replaced by the snapshot's state.
     *
     * @param  array<string, mixed>  $undone
     */
    public function beforeUndo(array $undone, GameSnapshot $snapshot): void;

    /**
     * The restored state, marked so everyone sees that the host undid a move.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function markUndone(array $state, int $userId): array;

    /**
     * Room columns to change when the host takes the table back to the lobby.
     *
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>
     */
    public function backToLobby(Room $room, ?array $state, bool $advance): array;

    /**
     * Called when the host picks this game: room columns to set (e.g. a starting mission).
     *
     * @return array<string, mixed>
     */
    public function chosen(Room $room, User $host): array;

    /**
     * Extra lobby props for this game's settings (public to the room, nothing hidden).
     *
     * @return array<string, mixed>
     */
    public function lobbyProps(Room $room, User $viewer): array;
}
