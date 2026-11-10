<?php

namespace App\Games\Moonwell;

use App\Games\GameDefinition;
use App\Games\Seating;
use App\Models\GameSnapshot;
use App\Models\Room;
use App\Models\User;

/**
 * Moonwell Garden: an original tile-drafting race for 2–8. Rules: MoonwellEngine.
 */
class Moonwell implements GameDefinition
{
    public function __construct(
        private readonly MoonwellEngine $engine,
    ) {}

    public function key(): string
    {
        return 'moonwell';
    }

    public function minPlayers(): int
    {
        return config('moonwell.min_players');
    }

    public function maxPlayers(): int
    {
        return config('moonwell.max_players');
    }

    public function page(): string
    {
        return 'Games/Moonwell/Game';
    }

    public function deal(Room $room, array $order): array
    {
        return $this->engine->start($order, Seating::nextStarter($room, $room->players->sortBy('seat')));
    }

    /**
     * Everything on the table is open information; only the bag's order stays on the server.
     */
    public function view(array $state, int $viewer, array $players, int $undoSteps): array
    {
        $mine = collect($state['players'])->firstWhere('id', $viewer);

        return [
            'phase' => $state['phase'],
            'turn' => $state['turn'],
            'winner' => $state['winner'],
            'slots' => $state['slots'],
            'choices' => $state['choices'],
            'fireflies' => $state['fireflies'],
            'lantern' => $state['lantern'],
            'ring' => $state['ring'],
            'available' => MoonwellEngine::available($state),
            'bag' => count($state['bag']),
            'players' => array_map(fn (array $player) => [
                'id' => $player['id'],
                'name' => $players[$player['id']]['name'] ?? '?',
                'seat' => $players[$player['id']]['seat'] ?? 0,
                'pos' => $player['pos'],
                'arrived' => $player['arrived'],
                'fireflies' => $player['fireflies'],
                'garden' => $player['garden'],
                'isMe' => $player['id'] === $viewer,
            ], $state['players']),
            'me' => [
                'id' => $viewer,
                'myTurn' => $state['phase'] === 'playing' && $state['turn'] === $viewer,
                'openCells' => $mine ? MoonwellEngine::openCells($mine['garden']) : [],
            ],
            'log' => array_slice($state['log'], -30),
            'logLength' => count($state['log']),
            'undoSteps' => $undoSteps,
        ];
    }

    public function waitingFor(array $state): ?array
    {
        return $state['phase'] === 'playing' ? [$state['turn'], 'push.turn'] : null;
    }

    public function afterMove(array $before, array $after): void {}

    public function beforeUndo(array $undone, GameSnapshot $snapshot): void {}

    public function markUndone(array $state, int $userId): array
    {
        return $this->engine->markUndone($state, $userId);
    }

    public function backToLobby(Room $room, ?array $state, bool $advance): array
    {
        return ['last_captain_seat' => Seating::seatOf($room, $state['starter'] ?? null) ?? $room->last_captain_seat];
    }

    public function chosen(Room $room, User $host): array
    {
        return [];
    }

    public function lobbyProps(Room $room, User $viewer): array
    {
        return ['tables' => config('moonwell.tables')];
    }
}
