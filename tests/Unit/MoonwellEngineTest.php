<?php

namespace Tests\Unit;

use App\Games\GameRuleException;
use App\Games\Moonwell\MoonwellEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

class MoonwellEngineTest extends TestCase
{
    private MoonwellEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new MoonwellEngine(new Randomizer(new Mt19937(7)));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function tableSizes(): array
    {
        return array_combine(array_map(fn (int $n) => "{$n} players", range(2, 8)), array_map(fn (int $n) => [$n], range(2, 8)));
    }

    #[DataProvider('tableSizes')]
    public function test_every_table_size_deals_a_full_ring_and_bag(int $players): void
    {
        $order = range(1, $players);
        $state = $this->engine->start($order, 1);
        $table = config("moonwell.tables.{$players}");

        $this->assertCount($table['slots'], $state['ring']);
        $this->assertNull($state['ring'][0], 'the lantern starts on slot 0');
        $this->assertCount($table['slots'] - 1, array_filter($state['ring']));
        $this->assertCount($table['tiles'] - $table['slots'] + 1, $state['bag']);
        $this->assertCount($table['choices'], MoonwellEngine::available($state));
        $this->assertSame(1, $state['turn']);

        foreach ($state['players'] as $player) {
            $this->assertSame($table['fireflies'], $player['fireflies']);
            $this->assertSame(0, $player['pos']);
        }
    }

    public function test_tiles_are_dealt_evenly_and_carry_one_to_four_wishes(): void
    {
        $state = $this->engine->start([1, 2], 1);
        $tiles = [...array_filter($state['ring']), ...$state['bag']];

        $this->assertSame([20, 20, 20, 20], array_values(array_count_values(array_column($tiles, 'color'))));

        foreach ($tiles as $tile) {
            $this->assertGreaterThanOrEqual(1, count($tile['wishes']));
            $this->assertLessThanOrEqual(4, count($tile['wishes']));

            foreach ($tile['wishes'] as $wish) {
                $this->assertGreaterThanOrEqual(1, count($wish['need']));
                $this->assertLessThanOrEqual(3, count($wish['need']));
            }
        }
    }

    public function test_turns_go_round_from_the_starter_while_pawns_share_stone_zero(): void
    {
        $state = $this->engine->start([10, 20, 30, 40], 30);
        $this->assertSame(30, $state['turn']);

        $state = $this->takeFirst($state, 30);
        $this->assertSame(40, $state['turn']);

        $state = $this->takeFirst($state, 40);
        $this->assertSame(10, $state['turn']);
    }

    public function test_the_pawn_furthest_back_plays_and_the_last_arrival_wins_a_tie(): void
    {
        $state = $this->engine->start([1, 2, 3], 1);
        $state['players'][0]['pos'] = 5;
        $state['players'][1]['pos'] = 3;
        $state['players'][2]['pos'] = 3;
        $state['players'][1]['arrived'] = 10;
        $state['players'][2]['arrived'] = 11;

        $this->assertSame(3, MoonwellEngine::current($state));

        $state['players'][1]['pos'] = 2;
        $this->assertSame(2, MoonwellEngine::current($state));
    }

    public function test_taking_moves_the_lantern_and_the_pawn(): void
    {
        $state = $this->engine->start([1, 2], 1);
        $slot = MoonwellEngine::available($state)[1];
        $tile = $state['ring'][$slot];

        $state = $this->engine->take($state, 1, $slot, 0, 0);

        $this->assertSame($slot, $state['lantern']);
        $this->assertNull($state['ring'][$slot]);
        $this->assertSame($tile['cost'], $state['players'][0]['pos']);
        $this->assertSame($tile['id'], $state['players'][0]['garden'][0]['tile']['id']);
        $this->assertSame('take', end($state['log'])['type']);
    }

    public function test_only_the_next_tiles_after_the_lantern_may_be_taken(): void
    {
        $state = $this->engine->start([1, 2], 1);

        $this->assertSame([1, 2, 3], MoonwellEngine::available($state));
        $this->expectRule('moonwell.errors.not_available', fn () => $this->engine->take($state, 1, 4, 0, 0));
    }

    public function test_only_the_player_whose_turn_it_is_may_take(): void
    {
        $state = $this->engine->start([1, 2], 1);

        $this->expectRule('moonwell.errors.not_your_turn', fn () => $this->engine->take($state, 2, 1, 0, 0));
    }

    public function test_tiles_go_next_to_your_garden(): void
    {
        $state = $this->engine->start([1, 2], 1);

        $this->expectRule('moonwell.errors.bad_spot', fn () => $this->engine->take($state, 1, 1, 1, 0));

        $state = $this->engine->take($state, 1, 1, 0, 0);
        $state['turn'] = 1;
        $this->expectRule('moonwell.errors.bad_spot', fn () => $this->engine->take($state, 1, 2, 2, 0));
        $this->expectRule('moonwell.errors.bad_spot', fn () => $this->engine->take($state, 1, 2, 0, 0));
        $this->expectRule('moonwell.errors.bad_spot', fn () => $this->engine->take($state, 1, 2, 1, 1));

        $state = $this->engine->take($state, 1, 2, -1, 0);
        $this->assertCount(2, $state['players'][0]['garden']);
    }

    public function test_open_cells_surround_the_garden(): void
    {
        $this->assertSame([[0, 0]], MoonwellEngine::openCells([]));

        $garden = [['x' => 0, 'y' => 0, 'tile' => $this->tile('blue')], ['x' => 1, 'y' => 0, 'tile' => $this->tile('blue')]];
        $this->assertEqualsCanonicalizing([[-1, 0], [2, 0], [0, 1], [0, -1], [1, 1], [1, -1]], MoonwellEngine::openCells($garden));
    }

    public function test_a_wish_needs_all_its_shapes_among_the_four_neighbours(): void
    {
        $centre = $this->tile('blue', [['green', 'green', 'pink']]);
        $garden = [
            ['x' => 0, 'y' => 0, 'tile' => $centre],
            ['x' => 1, 'y' => 0, 'tile' => $this->tile('green')],
            ['x' => -1, 'y' => 0, 'tile' => $this->tile('pink')],
            // Diagonal: not a neighbour
            ['x' => 1, 'y' => 1, 'tile' => $this->tile('green')],
        ];

        [$garden, $granted] = MoonwellEngine::grantWishes($garden, 10);
        $this->assertSame(0, $granted);

        $garden[] = ['x' => 0, 'y' => 1, 'tile' => $this->tile('green')];
        [$garden, $granted] = MoonwellEngine::grantWishes($garden, 10);
        $this->assertSame(1, $granted);
        $this->assertTrue($garden[0]['tile']['wishes'][0]['done']);

        // A wish only ever pays once
        [, $granted] = MoonwellEngine::grantWishes($garden, 10);
        $this->assertSame(0, $granted);
    }

    public function test_a_new_tile_can_make_older_tiles_wishes_come_true(): void
    {
        $state = $this->engine->start([1, 2], 1);
        $state['players'][0]['garden'] = [['x' => 0, 'y' => 0, 'tile' => $this->tile('blue', [['orange']])]];
        $state['ring'][1] = $this->tile('orange', [['pink', 'pink', 'pink']]) + ['cost' => 2];
        $state['ring'][1]['cost'] = 2;

        $state = $this->engine->take($state, 1, 1, 1, 0);

        $this->assertTrue($state['players'][0]['garden'][0]['tile']['wishes'][0]['done']);
        $this->assertSame(config('moonwell.tables.2.fireflies') - 1, $state['players'][0]['fireflies']);
        $this->assertSame(1, end($state['log'])['granted']);
    }

    public function test_placing_the_last_firefly_wins(): void
    {
        $state = $this->engine->start([1, 2], 1);
        $state['players'][0]['fireflies'] = 1;
        $state['players'][0]['garden'] = [['x' => 0, 'y' => 0, 'tile' => $this->tile('blue', [['orange']])]];
        $state['ring'][1] = $this->tile('orange');

        $state = $this->engine->take($state, 1, 1, 1, 0);

        $this->assertSame('won', $state['phase']);
        $this->assertSame(1, $state['winner']);
        $this->assertNull($state['turn']);
        $this->expectRule('moonwell.errors.game_over', fn () => $this->engine->take($state, 2, 2, 0, 0));
    }

    public function test_the_ring_refills_when_only_the_choices_are_left(): void
    {
        $state = $this->engine->start([1, 2], 1);
        $bag = count($state['bag']);
        // Leave four tiles in the ring; taking one leaves three, which triggers the refill
        foreach (range(5, 11) as $slot) {
            $state['ring'][$slot] = null;
        }

        $state = $this->engine->take($state, 1, 1, 0, 0);

        $this->assertCount(11, array_filter($state['ring']));
        $this->assertNull($state['ring'][1], 'the lantern stands on the taken slot');
        $this->assertSame($bag - 8, count($state['bag']));
        $this->assertSame(['type' => 'refill', 'count' => 8], end($state['log']));
    }

    public function test_an_empty_well_ends_the_game_with_the_fewest_fireflies_left(): void
    {
        $state = $this->engine->start([1, 2], 1);
        $state['bag'] = [];
        $state['ring'] = array_fill(0, 12, null);
        $state['ring'][1] = $this->tile('blue');
        $state['players'][0]['fireflies'] = 5;
        $state['players'][1]['fireflies'] = 3;

        $state = $this->engine->take($state, 1, 1, 0, 0);

        $this->assertSame('won', $state['phase']);
        $this->assertSame(2, $state['winner']);
        $this->assertSame('bag', end($state['log'])['reason']);
    }

    public function test_a_whole_eight_player_game_finishes(): void
    {
        $state = $this->engine->start(range(1, 8), 1);
        $moves = 0;

        while ($state['phase'] === 'playing' && $moves < 500) {
            $state = $this->takeFirst($state, $state['turn']);
            $moves++;
        }

        $this->assertSame('won', $state['phase']);
        $this->assertContains($state['winner'], range(1, 8));
    }

    /**
     * The first available tile, planted in the first open cell.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function takeFirst(array $state, int $player): array
    {
        $garden = collect($state['players'])->firstWhere('id', $player)['garden'];
        [$x, $y] = MoonwellEngine::openCells($garden)[0];

        return $this->engine->take($state, $player, MoonwellEngine::available($state)[0], $x, $y);
    }

    /**
     * @param  list<list<string>>  $wishes
     * @return array<string, mixed>
     */
    private function tile(string $color, array $wishes = []): array
    {
        static $id = 1000;

        return [
            'id' => $id++,
            'color' => $color,
            'cost' => 3,
            'wishes' => array_map(fn (array $need) => ['need' => $need, 'done' => false], $wishes),
        ];
    }

    private function expectRule(string $key, callable $move): void
    {
        try {
            $move();
            $this->fail("Expected {$key}");
        } catch (GameRuleException $exception) {
            $this->assertSame($key, $exception->key);
        }
    }
}
