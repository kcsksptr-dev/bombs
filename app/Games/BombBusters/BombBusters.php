<?php

namespace App\Games\BombBusters;

use App\Games\GameDefinition;
use App\Games\Seating;
use App\Models\GameSnapshot;
use App\Models\MissionCompletion;
use App\Models\Room;
use App\Models\User;

/**
 * Co-op wire cutting with a mission campaign. Rules: GameEngine; per-player view: GameView.
 */
class BombBusters implements GameDefinition
{
    public function __construct(
        private readonly GameEngine $engine,
        private readonly GameView $view,
    ) {}

    public function key(): string
    {
        return 'bombbusters';
    }

    public function minPlayers(): int
    {
        return config('bombbusters.min_players');
    }

    public function maxPlayers(): int
    {
        return config('bombbusters.max_players');
    }

    public function page(): string
    {
        return 'Games/BombBusters/Game';
    }

    public function deal(Room $room, array $order): array
    {
        return $this->engine->start($order, Seating::nextStarter($room, $room->players->sortBy('seat')), $room->mission);
    }

    public function view(array $state, int $viewer, array $players, int $undoSteps): array
    {
        return $this->view->for($state, $viewer, $players, $undoSteps);
    }

    public function waitingFor(array $state): ?array
    {
        if ($state['pending']['player'] ?? null) {
            return [$state['pending']['player'], 'push.reveal'];
        }

        return $state['phase'] === 'playing' ? [$state['turn'], 'push.turn'] : null;
    }

    /**
     * Everyone who played a won mission gets it ticked off in their campaign.
     */
    public function afterMove(array $before, array $after): void
    {
        if ($before['phase'] === 'won' || $after['phase'] !== 'won') {
            return;
        }

        foreach ($after['order'] as $userId) {
            MissionCompletion::firstOrCreate(
                ['user_id' => $userId, 'mission' => $after['mission']],
                ['completed_at' => now()],
            );
        }
    }

    /**
     * Takes back what an undone winning move recorded. Anyone who won this mission in an earlier
     * game keeps their tick: their completion is older than the move.
     */
    public function beforeUndo(array $undone, GameSnapshot $snapshot): void
    {
        if ($undone['phase'] !== 'won') {
            return;
        }

        MissionCompletion::whereIn('user_id', $undone['order'])
            ->where('mission', $undone['mission'])
            ->where('completed_at', '>=', $snapshot->created_at)
            ->delete();
    }

    public function markUndone(array $state, int $userId): array
    {
        return $this->engine->markUndone($state, $userId);
    }

    public function backToLobby(Room $room, ?array $state, bool $advance): array
    {
        $next = $room->mission + 1;
        $won = ($state['phase'] ?? null) === 'won';

        return [
            'last_captain_seat' => Seating::seatOf($room, $state['captain'] ?? null) ?? $room->last_captain_seat,
            // Only a won mission moves the table on, and only if there is a next one
            'mission' => $advance && $won && config("missions.{$next}") ? $next : $room->mission,
        ];
    }

    /**
     * Picking Bomb Busters starts at the host's first unfinished mission.
     */
    public function chosen(Room $room, User $host): array
    {
        return ['mission' => self::missionOptions($host)['next']];
    }

    public function lobbyProps(Room $room, User $viewer): array
    {
        return ['missions' => self::missionOptions($viewer)];
    }

    /**
     * The campaign as the mission picker shows it: every mission's rules, plus this player's progress.
     *
     * @return array{missions: list<array<string, mixed>>, detonator: array<int, int>, completed: list<int>, next: int}
     */
    public static function missionOptions(User $viewer): array
    {
        $completed = $viewer->completedMissions();
        $numbers = array_keys(config('missions'));

        return [
            'missions' => array_map(function (int $number) {
                $mission = GameEngine::mission($number);

                return [
                    'number' => $number,
                    'detonatorBonus' => $mission['detonator_bonus'],
                    'yellow' => $mission['yellow'],
                    'red' => $mission['red'],
                    'sequence' => $mission['sequence'],
                    'equipment' => [
                        'count' => count($mission['equipment']['cards'] ?? []) ?: $mission['equipment']['count'],
                        'cards' => $mission['equipment']['cards'] ?? null,
                        'rule' => $mission['equipment']['rule'],
                    ],
                ];
            }, $numbers),
            'detonator' => config('bombbusters.detonator'),
            'completed' => $completed,
            // First mission this player hasn't won yet (or the last one, once the campaign is done)
            'next' => collect($numbers)->first(fn (int $number) => ! in_array($number, $completed, true)) ?? max($numbers),
        ];
    }
}
