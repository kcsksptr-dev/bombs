<?php

namespace App\Games\Moonwell;

use App\Games\GameRuleException;
use Random\Randomizer;

/**
 * Moonwell Garden's rules, as pure functions over a plain-array state: every method takes the
 * current state and returns the next one, or throws GameRuleException with a translation key.
 * Numbers live in config/moonwell.php.
 *
 * A tile is ['id', 'color', 'cost', 'wishes' => list of ['need' => list<color>, 'done' => bool]].
 * A wish comes true when the tile's four neighbours (left, right, up, down) include every colour
 * in `need`, counted with repeats: ['green', 'green', 'blue'] needs two green neighbours and a blue one.
 *
 * State shape (all of it public except the bag's order):
 *   game         'moonwell'
 *   phase        'playing' | 'won'
 *   order        user ids in seat order
 *   starter      user id who took the first turn (the next game starts one seat on)
 *   slots        ring size; choices: how many tiles after the lantern may be taken
 *   fireflies    how many each player starts with
 *   ring         list of tile|null, one per slot; the lantern stands on slot `lantern` (always empty)
 *   bag          list of tiles still to come, in draw order (only its size reaches clients)
 *   players      list, in seat order, of ['id', 'pos' (time path), 'arrived', 'fireflies' (left to
 *                place), 'garden' => list of ['x', 'y', 'tile']]; gardens grow freely in every direction
 *   arrivals     counter behind `arrived`: on a shared stone the pawn that arrived last moves first
 *   turn         user id who plays next (null once the game is over)
 *   winner       user id, once the game is over
 *   log          public history entries (see the log() calls), rendered by the client
 */
class MoonwellEngine
{
    /** Gardens stay within this many cells of the first tile, so a bad request can't grow them forever */
    public const REACH = 20;

    public function __construct(
        private readonly Randomizer $randomizer = new Randomizer,
    ) {}

    /**
     * The table settings for a player count.
     *
     * @return array{slots: int, choices: int, tiles: int, fireflies: int}
     */
    public static function table(int $players): array
    {
        return config("moonwell.tables.{$players}") ?? throw new GameRuleException('lobby.errors.invalid_capacity', [
            'min' => config('moonwell.min_players'),
            'max' => config('moonwell.max_players'),
        ]);
    }

    /**
     * @param  list<int>  $order  user ids in seat order
     * @return array<string, mixed>
     */
    public function start(array $order, int $starter): array
    {
        $table = self::table(count($order));
        $bag = $this->randomizer->shuffleArray($this->makeTiles($table['tiles']));

        // Everyone starts on stone 0. Ties go to the pawn that arrived last, so the starter "arrived"
        // last and the others in reverse seat order after them: turns then go round the table.
        $from = array_search($starter, $order, true) ?: 0;
        $turnOrder = [...array_slice($order, $from), ...array_slice($order, 0, $from)];
        $players = array_map(fn (int $id) => [
            'id' => $id,
            'pos' => 0,
            'arrived' => count($order) - array_search($id, $turnOrder, true),
            'fireflies' => $table['fireflies'],
            'garden' => [],
        ], $order);

        $ring = array_fill(0, $table['slots'], null);

        for ($slot = 1; $slot < $table['slots']; $slot++) {
            $ring[$slot] = array_shift($bag);
        }

        $state = [
            'game' => 'moonwell',
            'phase' => 'playing',
            'order' => array_values($order),
            'starter' => $starter,
            'slots' => $table['slots'],
            'choices' => $table['choices'],
            'fireflies' => $table['fireflies'],
            'ring' => $ring,
            'lantern' => 0,
            'bag' => $bag,
            'players' => $players,
            'arrivals' => count($order),
            'turn' => null,
            'winner' => null,
            'log' => [],
        ];
        $state['turn'] = self::current($state);

        return $this->log($state, ['type' => 'start', 'player' => $state['turn']]);
    }

    /**
     * Take the tile on `slot` and plant it at (x, y) in your garden.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function take(array $state, int $actor, int $slot, int $x, int $y): array
    {
        if ($state['phase'] !== 'playing') {
            throw new GameRuleException('moonwell.errors.game_over');
        }

        if ($state['turn'] !== $actor) {
            throw new GameRuleException('moonwell.errors.not_your_turn');
        }

        if (! in_array($slot, self::available($state), true)) {
            throw new GameRuleException('moonwell.errors.not_available');
        }

        $index = self::playerIndex($state, $actor);
        $player = $state['players'][$index];

        if (! self::canPlant($player['garden'], $x, $y)) {
            throw new GameRuleException('moonwell.errors.bad_spot');
        }

        $tile = $state['ring'][$slot];
        $tile['wishes'] = array_map(fn (array $wish) => $wish + ['done' => false], $tile['wishes']);
        $state['ring'][$slot] = null;
        $state['lantern'] = $slot;

        $player['garden'][] = ['x' => $x, 'y' => $y, 'tile' => $tile];
        [$player['garden'], $granted] = self::grantWishes($player['garden'], $player['fireflies']);
        $player['fireflies'] -= $granted;
        $player['pos'] += $tile['cost'];
        $player['arrived'] = ++$state['arrivals'];
        $state['players'][$index] = $player;

        $state = $this->log($state, [
            'type' => 'take',
            'player' => $actor,
            'tile' => $tile['id'],
            'color' => $tile['color'],
            'cost' => $tile['cost'],
            'x' => $x,
            'y' => $y,
            'granted' => $granted,
        ]);

        if ($player['fireflies'] === 0) {
            return $this->finish($state, $actor, 'fireflies');
        }

        $state = $this->refill($state);

        if (self::available($state) === []) {
            return $this->finish($state, self::leader($state), 'bag');
        }

        $state['turn'] = self::current($state);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function markUndone(array $state, int $userId): array
    {
        return $this->log($state, ['type' => 'undo', 'player' => $userId]);
    }

    /**
     * Who plays next: the pawn furthest back; on a shared stone, the one that arrived last.
     *
     * @param  array<string, mixed>  $state
     */
    public static function current(array $state): int
    {
        $players = $state['players'];
        usort($players, fn (array $a, array $b) => [$a['pos'], $b['arrived']] <=> [$b['pos'], $a['arrived']]);

        return $players[0]['id'];
    }

    /**
     * Ring slots that may be taken now: the first `choices` tiles clockwise after the lantern.
     *
     * @param  array<string, mixed>  $state
     * @return list<int>
     */
    public static function available(array $state): array
    {
        $slots = [];

        for ($step = 1; $step < $state['slots'] && count($slots) < $state['choices']; $step++) {
            $slot = ($state['lantern'] + $step) % $state['slots'];

            if ($state['ring'][$slot] !== null) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * The first tile goes at (0, 0); after that, any empty cell next to a tile you have.
     *
     * @param  list<array{x: int, y: int, tile: array<string, mixed>}>  $garden
     */
    public static function canPlant(array $garden, int $x, int $y): bool
    {
        if ($garden === []) {
            return $x === 0 && $y === 0;
        }

        if (abs($x) > self::REACH || abs($y) > self::REACH || self::at($garden, $x, $y)) {
            return false;
        }

        foreach (self::neighbours($x, $y) as [$nx, $ny]) {
            if (self::at($garden, $nx, $ny)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every cell a tile may go in.
     *
     * @param  list<array{x: int, y: int, tile: array<string, mixed>}>  $garden
     * @return list<array{int, int}>
     */
    public static function openCells(array $garden): array
    {
        if ($garden === []) {
            return [[0, 0]];
        }

        $cells = [];

        foreach ($garden as $cell) {
            foreach (self::neighbours($cell['x'], $cell['y']) as [$x, $y]) {
                if (! in_array([$x, $y], $cells, true) && self::canPlant($garden, $x, $y)) {
                    $cells[] = [$x, $y];
                }
            }
        }

        return $cells;
    }

    /**
     * Marks every wish that has come true, while fireflies last.
     *
     * @param  list<array{x: int, y: int, tile: array<string, mixed>}>  $garden
     * @return array{list<array{x: int, y: int, tile: array<string, mixed>}>, int}
     */
    public static function grantWishes(array $garden, int $fireflies): array
    {
        $granted = 0;

        foreach ($garden as $i => $cell) {
            $around = [];

            foreach (self::neighbours($cell['x'], $cell['y']) as [$x, $y]) {
                if ($neighbour = self::at($garden, $x, $y)) {
                    $around[] = $neighbour['tile']['color'];
                }
            }

            foreach ($cell['tile']['wishes'] as $w => $wish) {
                if ($wish['done'] || $granted >= $fireflies || ! self::covers($around, $wish['need'])) {
                    continue;
                }

                $garden[$i]['tile']['wishes'][$w]['done'] = true;
                $granted++;
            }
        }

        return [$garden, $granted];
    }

    /**
     * @param  list<string>  $around
     * @param  list<string>  $need
     */
    private static function covers(array $around, array $need): bool
    {
        $have = array_count_values($around);

        foreach (array_count_values($need) as $color => $count) {
            if (($have[$color] ?? 0) < $count) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{x: int, y: int, tile: array<string, mixed>}>  $garden
     * @return array{x: int, y: int, tile: array<string, mixed>}|null
     */
    private static function at(array $garden, int $x, int $y): ?array
    {
        foreach ($garden as $cell) {
            if ($cell['x'] === $x && $cell['y'] === $y) {
                return $cell;
            }
        }

        return null;
    }

    /**
     * @return list<array{int, int}>
     */
    private static function neighbours(int $x, int $y): array
    {
        return [[$x + 1, $y], [$x - 1, $y], [$x, $y + 1], [$x, $y - 1]];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function playerIndex(array $state, int $userId): int
    {
        foreach ($state['players'] as $index => $player) {
            if ($player['id'] === $userId) {
                return $index;
            }
        }

        throw new GameRuleException('moonwell.errors.not_your_turn');
    }

    /**
     * The bag ran out: fewest fireflies left wins, then whoever is further back on the path.
     *
     * @param  array<string, mixed>  $state
     */
    private static function leader(array $state): int
    {
        $players = $state['players'];
        usort($players, fn (array $a, array $b) => [$a['fireflies'], $a['pos']] <=> [$b['fireflies'], $b['pos']]);

        return $players[0]['id'];
    }

    /**
     * When only `choices` tiles or fewer are left in the ring, every empty slot but the lantern's
     * is refilled from the bag, going round from the lantern.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function refill(array $state): array
    {
        if (count(array_filter($state['ring'])) > $state['choices'] || $state['bag'] === []) {
            return $state;
        }

        $added = 0;

        for ($step = 1; $step < $state['slots'] && $state['bag'] !== []; $step++) {
            $slot = ($state['lantern'] + $step) % $state['slots'];

            if ($state['ring'][$slot] === null) {
                $state['ring'][$slot] = array_shift($state['bag']);
                $added++;
            }
        }

        return $this->log($state, ['type' => 'refill', 'count' => $added]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function finish(array $state, int $winner, string $reason): array
    {
        $state['phase'] = 'won';
        $state['winner'] = $winner;
        $state['turn'] = null;

        return $this->log($state, ['type' => 'won', 'player' => $winner, 'reason' => $reason]);
    }

    /**
     * Colours and costs are dealt evenly; each tile gets one of its cost's wish layouts.
     *
     * @return list<array<string, mixed>>
     */
    private function makeTiles(int $count): array
    {
        $colors = config('moonwell.colors');
        $costs = config('moonwell.costs');
        $tiles = [];

        for ($i = 0; $i < $count; $i++) {
            $cost = $costs[intdiv($i, count($colors)) % count($costs)];
            $layouts = config("moonwell.wishes.{$cost}");
            $layout = $layouts[$this->randomizer->getInt(0, count($layouts) - 1)];

            $tiles[] = [
                'id' => $i + 1,
                'color' => $colors[$i % count($colors)],
                'cost' => $cost,
                'wishes' => array_map(function (int $shapes) use ($colors) {
                    $need = [];

                    for ($n = 0; $n < $shapes; $n++) {
                        $need[] = $colors[$this->randomizer->getInt(0, count($colors) - 1)];
                    }

                    // Same colours together, in the config's order, so tiles read the same way
                    usort($need, fn (string $a, string $b) => array_search($a, $colors, true) <=> array_search($b, $colors, true));

                    return ['need' => $need];
                }, $layout),
            ];
        }

        return $tiles;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function log(array $state, array $entry): array
    {
        $state['log'][] = $entry;

        return $state;
    }
}
