<?php

namespace Tests\Unit;

use App\Game\GameEngine;
use App\Game\GameRuleException;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

class GameEngineTest extends TestCase
{
    private GameEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new GameEngine(new Randomizer(new Mt19937(42)));

        // A blue-only mission without equipment, for tests about dealing and turn flow
        config(['missions.99' => []]);
    }

    /**
     * @return array<string, array{int, list<int>}>
     */
    public static function tableSizes(): array
    {
        return [
            '2 players' => [2, [24, 24]],
            '4 players' => [4, [12, 12, 12, 12]],
            '5 players' => [5, [10, 10, 10, 9, 9]],
            '6 players' => [6, [8, 8, 8, 8, 8, 8]],
            '7 players' => [7, [7, 7, 7, 7, 7, 7, 6]],
            '8 players' => [8, [6, 6, 6, 6, 6, 6, 6, 6]],
        ];
    }

    /**
     * @param  list<int>  $expected  stand sizes in deal order, starting with the Captain
     */
    #[DataProvider('tableSizes')]
    public function test_all_48_blue_wires_are_dealt_evenly_from_the_captain(int $players, array $expected): void
    {
        $order = range(1, $players);
        $state = $this->engine->start($order, 2, 99);

        $dealOrder = array_merge(array_slice($order, 1), [1]);
        $this->assertSame($expected, array_map(fn ($id) => count($state['hands'][$id]), $dealOrder));

        $all = array_merge(...array_values($state['hands']));
        $this->assertCount(48, $all);
        $counts = array_count_values(array_column($all, 'value'));
        ksort($counts);
        $this->assertSame(array_fill(1, 12, 4), $counts);
        $this->assertSortedStands($state);

        $this->assertSame('setup', $state['phase']);
        $this->assertNull($state['turn']);
    }

    public function test_the_mission_sets_the_detonator_coloured_wires_and_equipment(): void
    {
        $this->assertSame([6, 0, 0, 2], $this->summary($this->engine->start(range(1, 4), 1, 1)));
        $this->assertSame([4, 2, 1, 3], $this->summary($this->engine->start(range(1, 4), 1, 8)));
        $this->assertSame([3, 4, 3, 2], $this->summary($this->engine->start(range(1, 4), 1, 16)));
        $this->assertSame(5, $this->engine->start(range(1, 8), 1, 16)['detonator']['limit']);
        $this->assertSame([4, 8, 12], $this->engine->start(range(1, 4), 1, 5)['sequence']);

        $this->assertRule('lobby.errors.invalid_mission', fn () => $this->engine->start(range(1, 4), 1, 999));
    }

    public function test_coloured_wires_come_from_the_public_candidates_and_sort_between_blue(): void
    {
        $state = $this->engine->start(range(1, 3), 1, 16);
        $all = array_merge(...array_values($state['hands']));

        $this->assertCount(5, $state['candidates']['yellow']);
        $this->assertCount(4, $state['candidates']['red']);

        foreach (['yellow' => 4, 'red' => 3] as $color => $count) {
            $values = array_column(array_filter($all, fn ($wire) => $wire['color'] === $color), 'value');
            $this->assertCount($count, $values);
            $this->assertEmpty(array_diff($values, $state['candidates'][$color]), "{$color} wires come from the candidates");
        }

        $this->assertSortedStands($state);
        $this->assertCount(55, $all);
    }

    public function test_equipment_is_dealt_from_the_catalog_ordered_by_unlock_number(): void
    {
        $state = $this->engine->start(range(1, 4), 1, 4);

        $this->assertCount(3, $state['equipment']);
        $unlocks = array_column($state['equipment'], 'unlock');
        $sorted = $unlocks;
        sort($sorted);
        $this->assertSame($sorted, $unlocks);

        foreach ($state['equipment'] as $card) {
            $this->assertSame(config("bombbusters.equipment.{$card['id']}.unlock"), $card['unlock']);
            $this->assertSame('cut_pair', $card['rule']);
            $this->assertFalse($card['used']);
        }
    }

    public function test_setup_ends_when_everyone_placed_an_info_token_and_the_captain_starts(): void
    {
        $state = $this->state([1 => [3, 5], 2 => [4, 6]], phase: 'setup', captain: 2);

        $state = $this->engine->placeInfoToken($state, 1, 1);
        $this->assertSame(5, $state['hands'][1][1]['info']);
        $this->assertSame('setup', $state['phase']);

        $this->assertRule('game.errors.info_already_placed', fn () => $this->engine->placeInfoToken($state, 1, 0));
        $this->assertRule('game.errors.setup_not_finished', fn () => $this->engine->dualCut($state, 2, 1, 0, 4));

        $state = $this->engine->placeInfoToken($state, 2, 0);
        $this->assertSame('playing', $state['phase']);
        $this->assertSame(2, $state['turn']);
        $this->assertSame('begin', end($state['log'])['type']);
    }

    #[DataProvider('missions')]
    public function test_every_player_reveals_exactly_one_blue_wire_at_setup_on_any_mission(int $mission): void
    {
        $state = $this->engine->start([1, 2], 1, $mission);
        $this->assertSame('setup', $state['phase']);
        $this->assertTrue($this->engine->needsInfoToken($state, 1));

        $blue = fn (int $player, int $skip = 0) => array_keys(array_filter($state['hands'][$player], fn ($wire) => $wire['color'] === 'blue'))[$skip];

        $state = $this->engine->placeInfoToken($state, 1, $blue(1));
        $this->assertFalse($this->engine->needsInfoToken($state, 1));
        $this->assertRule('game.errors.info_already_placed', fn () => $this->engine->placeInfoToken($state, 1, $blue(1, 1)));

        $state = $this->engine->placeInfoToken($state, 2, $blue(2));
        $this->assertSame('playing', $state['phase']);
        $this->assertSame(2, collect(array_merge(...array_values($state['hands'])))->whereNotNull('info')->count());
    }

    public function test_the_setup_reveal_must_be_a_blue_wire(): void
    {
        $state = $this->state([1 => ['y5', 8], 2 => [4, 6]], phase: 'setup');

        $this->assertRule('game.errors.info_blue_only', fn () => $this->engine->placeInfoToken($state, 1, 0));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function missions(): array
    {
        return ['first' => [1], 'with red' => [8], 'last' => [16]];
    }

    public function test_correct_dual_cut_cuts_both_wires_and_passes_the_turn(): void
    {
        $state = $this->state([1 => [2, 7], 2 => [7, 9], 3 => [1, 5]], turn: 1);

        $state = $this->engine->dualCut($state, 1, 2, 0, 7);

        $this->assertTrue($state['hands'][2][0]['cut']);
        $this->assertTrue($state['hands'][1][1]['cut']);
        $this->assertSame(0, $state['detonator']['mistakes']);
        $this->assertSame(2, $state['turn']);
        $this->assertTrue(end($state['log'])['success']);
    }

    public function test_wrong_dual_cut_ticks_the_detonator_and_reveals_the_real_value(): void
    {
        $state = $this->state([1 => [2, 7], 2 => [8, 9]], turn: 1);

        $state = $this->engine->dualCut($state, 1, 2, 0, '7');

        $this->assertFalse($state['hands'][2][0]['cut']);
        $this->assertSame(8, $state['hands'][2][0]['info']);
        $this->assertSame(1, $state['detonator']['mistakes']);
        $this->assertSame(
            ['type' => 'dual_cut', 'actor' => 1, 'target' => 2, 'index' => 0, 'value' => 7, 'success' => false, 'actual' => 8, 'stabilized' => false],
            end($state['log']),
        );
        $this->assertSame(2, $state['turn']);
    }

    public function test_yellow_wires_are_cut_by_naming_yellow(): void
    {
        $state = $this->state([1 => [2, 'y4'], 2 => ['y7', 9], 3 => [2, 9]], turn: 1);

        $this->assertSame([2, 'yellow'], $this->engine->heldValues($state, 1));

        $hit = $this->engine->dualCut($state, 1, 2, 0, 'yellow');
        $this->assertTrue($hit['hands'][2][0]['cut']);
        $this->assertTrue($hit['hands'][1][1]['cut']);

        // Naming a number on a yellow wire is a miss, and the token says "yellow"
        $miss = $this->engine->dualCut($state, 1, 2, 0, 2);
        $this->assertSame('yellow', $miss['hands'][2][0]['info']);
        $this->assertSame(1, $miss['detonator']['mistakes']);

        // Naming yellow on a blue wire is a miss too
        $miss = $this->engine->dualCut($state, 1, 3, 1, 'yellow');
        $this->assertSame(9, $miss['hands'][3][1]['info']);
    }

    public function test_cutting_a_red_wire_blows_the_bomb_immediately(): void
    {
        $state = $this->state([1 => [2, 7], 2 => [2, 'r6']], turn: 1);

        $state = $this->engine->dualCut($state, 1, 2, 1, 7);

        $this->assertSame('lost', $state['phase']);
        $this->assertSame('red', $state['log'][count($state['log']) - 2]['actual']);
        $this->assertSame(0, $state['detonator']['mistakes']);
    }

    public function test_red_can_never_be_named(): void
    {
        $state = $this->state([1 => [2, 'r7'], 2 => ['r3', 9]], turn: 1);

        $this->assertSame([2], $this->engine->heldValues($state, 1));
        $this->assertRule('game.errors.value_not_held', fn () => $this->engine->dualCut($state, 1, 2, 0, 'red'));
    }

    public function test_only_red_left_means_revealing_it(): void
    {
        $state = $this->state([1 => [5, 'r7', 'r9'], 2 => [5, 8], 3 => [8]], turn: 1);

        $this->assertFalse($this->engine->canRevealRed($state, 1));
        $this->assertRule('game.errors.reveal_red_not_allowed', fn () => $this->engine->revealRed($state, 1));

        $state['hands'][1][0]['cut'] = true;
        $state['hands'][2][0]['cut'] = true;
        $this->assertTrue($this->engine->canRevealRed($state, 1));

        $state = $this->engine->revealRed($state, 1);
        $this->assertTrue($state['hands'][1][1]['revealed']);
        $this->assertTrue($state['hands'][1][2]['cut']);
        $this->assertSame(['type' => 'reveal_red', 'actor' => 1, 'count' => 2], end($state['log']));
        $this->assertSame(2, $state['turn']);

        // Blue 8s are the last wires: cutting them wins even though the reds were never "cut"
        $state = $this->engine->dualCut($state, 2, 3, 0, 8);
        $this->assertSame('won', $state['phase']);
    }

    public function test_the_bomb_explodes_when_the_detonator_runs_out(): void
    {
        $state = $this->state([1 => [2, 7], 2 => [8, 9]], turn: 1);
        $state['detonator'] = ['mistakes' => 3, 'limit' => 4];

        $state = $this->engine->dualCut($state, 1, 2, 1, 2);

        $this->assertSame('lost', $state['phase']);
        $this->assertNull($state['turn']);
        $this->assertRule('game.errors.wrong_phase', fn () => $this->engine->soloCut($state, 1, 2));
    }

    public function test_dual_cut_rules(): void
    {
        $state = $this->state([1 => [2, 7], 2 => [7, 9]], turn: 1);
        $state['hands'][2][1]['cut'] = true;

        $this->assertRule('game.errors.not_your_turn', fn () => $this->engine->dualCut($state, 2, 1, 0, 7));
        $this->assertRule('game.errors.not_own_wire', fn () => $this->engine->dualCut($state, 1, 1, 0, 2));
        $this->assertRule('game.errors.value_not_held', fn () => $this->engine->dualCut($state, 1, 2, 0, 9));
        $this->assertRule('game.errors.value_not_held', fn () => $this->engine->dualCut($state, 1, 2, 0, 'yellow'));
        $this->assertRule('game.errors.wire_already_cut', fn () => $this->engine->dualCut($state, 1, 2, 1, 7));
        $this->assertRule('game.errors.no_such_wire', fn () => $this->engine->dualCut($state, 1, 2, 5, 7));
        $this->assertRule('game.errors.not_a_player', fn () => $this->engine->dualCut($state, 1, 99, 0, 7));
    }

    public function test_solo_cut_needs_every_remaining_copy_of_the_value(): void
    {
        $state = $this->state([1 => [3, 3, 5, 'y6'], 2 => [3, 5, 9, 'r2']], turn: 1);

        $this->assertSame(['yellow'], $this->engine->soloCutValues($state, 1));
        $this->assertSame([9], $this->engine->soloCutValues($state, 2), 'red is never solo-cut');
        $this->assertRule('game.errors.solo_cut_not_allowed', fn () => $this->engine->soloCut($state, 1, 3));

        // Once the other 3 is cut, player 1 holds all remaining 3s
        $state['hands'][2][0]['cut'] = true;
        $this->assertSame([3, 'yellow'], $this->engine->soloCutValues($state, 1));

        $state = $this->engine->soloCut($state, 1, 3);
        $this->assertTrue($state['hands'][1][0]['cut']);
        $this->assertTrue($state['hands'][1][1]['cut']);
        $this->assertFalse($state['hands'][1][2]['cut']);
        $this->assertSame(['type' => 'solo_cut', 'actor' => 1, 'value' => 3, 'count' => 2], end($state['log']));
    }

    public function test_turn_skips_players_whose_stand_is_finished(): void
    {
        $state = $this->state([1 => [4, 8], 2 => [6], 3 => [4, 8]], turn: 1);
        $state['hands'][2][0]['cut'] = true;

        $state = $this->engine->dualCut($state, 1, 3, 0, 4);

        $this->assertSame(3, $state['turn']);
    }

    public function test_cutting_the_last_wire_wins(): void
    {
        $state = $this->state([1 => [5], 2 => [5]], turn: 2);

        $state = $this->engine->dualCut($state, 2, 1, 0, 5);

        $this->assertSame('won', $state['phase']);
        $this->assertSame('won', end($state['log'])['type']);
    }

    public function test_equipment_unlocks_when_two_wires_of_its_number_are_cut_and_works_once(): void
    {
        $state = $this->state([1 => [2, 2, 7], 2 => [2, 2, 8]], turn: 1, equipment: ['rewinder']);
        $state = $this->withUnlock($state, 'rewinder', 2);
        $state['detonator']['mistakes'] = 2;

        $this->assertRule('game.errors.equipment_locked_cut_pair', fn () => $this->engine->useEquipment($state, 1, 'rewinder'));
        $this->assertRule('game.errors.equipment_not_in_play', fn () => $this->engine->useEquipment($state, 1, 'radar'));

        $state['hands'][1][0]['cut'] = true;
        $state['hands'][2][0]['cut'] = true;
        $this->assertTrue($this->engine->isUnlocked($state, $state['equipment'][0]));

        $state = $this->engine->useEquipment($state, 1, 'rewinder');
        $this->assertSame(1, $state['detonator']['mistakes']);
        $this->assertSame(1, $state['turn'], 'equipment does not end the turn');
        $this->assertRule('game.errors.equipment_used', fn () => $this->engine->useEquipment($state, 1, 'rewinder'));
    }

    public function test_stabilizer_absorbs_one_wrong_cut_this_turn(): void
    {
        $state = $this->unlocked($this->state([1 => [3, 7], 2 => [8, 9], 3 => [3, 9]], turn: 1, equipment: ['stabilizer']));

        $state = $this->engine->useEquipment($state, 1, 'stabilizer');
        $state = $this->engine->dualCut($state, 1, 2, 0, 7);

        $this->assertSame(0, $state['detonator']['mistakes']);
        $this->assertSame(8, $state['hands'][2][0]['info']);
        $this->assertTrue(end($state['log'])['stabilized']);
        $this->assertNull($state['stabilized'], 'protection ends with the turn');
    }

    public function test_post_it_label_and_radar(): void
    {
        $state = $this->unlocked($this->state([1 => [3, 3, 6, 'y8'], 2 => [6, 9], 3 => [3, 9]], turn: 1, equipment: ['post_it', 'label', 'radar']));

        $state = $this->engine->useEquipment($state, 1, 'post_it', ['index' => 2]);
        $this->assertSame(6, $state['hands'][1][2]['info']);

        $yellowFirst = $this->unlocked($this->state([1 => ['y8', 3], 2 => [3]], turn: 1, equipment: ['post_it']));
        $this->assertRule('game.errors.info_blue_only', fn () => $this->engine->useEquipment($yellowFirst, 1, 'post_it', ['index' => 0]));

        $state = $this->engine->useEquipment($state, 1, 'label', ['index' => 0]);
        $this->assertSame([['player' => 1, 'index' => 0, 'equal' => true]], $state['labels']);

        $state = $this->engine->useEquipment($state, 1, 'radar', ['value' => 9]);
        $this->assertSame([2, 3], end($state['log'])['holders']);
        $this->assertSame(1, $state['turn']);
    }

    public function test_a_sequence_blocks_later_numbers_until_earlier_ones_are_finished(): void
    {
        $state = $this->state([1 => [4, 8, 8], 2 => [4, 8, 9], 3 => [8, 9]], turn: 1);
        $state['sequence'] = [4, 8];

        $this->assertSame([['kind' => 4, 'done' => false, 'open' => true], ['kind' => 8, 'done' => false, 'open' => false]], $this->engine->sequenceProgress($state));
        $this->assertSame([4], $this->engine->allowedHeldValues($state, 1));

        // Naming 8 is refused before anything about the target is revealed, and it costs nothing
        $this->assertRule('game.errors.sequence_locked', fn () => $this->engine->dualCut($state, 1, 3, 0, 8));

        $state = $this->engine->dualCut($state, 1, 2, 0, 4);
        $this->assertSame(['done' => true, 'open' => false], array_intersect_key($this->engine->sequenceProgress($state)[0], ['done' => 1, 'open' => 1]));
        $this->assertTrue($this->engine->sequenceProgress($state)[1]['open']);
        $this->assertSame([8, 9], $this->engine->allowedHeldValues($state, 2), '9 is not in the sequence, so it was never locked');
    }

    public function test_solo_cut_respects_the_sequence(): void
    {
        $state = $this->state([1 => [4, 8, 8, 8, 8], 2 => [4, 9]], turn: 1);
        $state['sequence'] = [4, 8];

        $this->assertSame([], $this->engine->soloCutValues($state, 1));
        $this->assertRule('game.errors.sequence_locked', fn () => $this->engine->soloCut($state, 1, 8));
    }

    public function test_a_player_with_no_legal_move_skips_their_turn(): void
    {
        // Player 1 holds only 8s while the 4s are all on player 2's stand
        $state = $this->state([1 => [8, 8], 2 => [4, 4, 4, 4, 8, 8]], turn: 1);
        $state['sequence'] = [4, 8];

        $this->assertTrue($this->engine->mustPass($state, 1));
        $this->assertFalse($this->engine->mustPass($state, 2));
        $this->assertRule('game.errors.pass_not_allowed', fn () => $this->engine->pass(array_merge($state, ['turn' => 2]), 2));

        $state = $this->engine->pass($state, 1);
        $this->assertSame(2, $state['turn']);
        $this->assertSame(['type' => 'pass', 'actor' => 1], end($state['log']));
    }

    public function test_equipment_unlock_rules(): void
    {
        $state = $this->state([1 => [5, 5, 5, 7], 2 => [5, 9]], turn: 1, equipment: ['radar']);
        $card = fn (string $rule) => ['id' => 'radar', 'unlock' => 5, 'rule' => $rule, 'used' => false];
        $state['hands'][1][0]['cut'] = true;
        $state['hands'][1][1]['cut'] = true;

        $this->assertTrue($this->engine->isUnlocked($state, $card('cut_pair')));
        $this->assertFalse($this->engine->isUnlocked($state, $card('cut_all')));
        $this->assertTrue($this->engine->isUnlocked($state, $card('from_start')));
        $this->assertFalse($this->engine->isUnlocked($state, $card('after_mistake')));

        $state['hands'][1][2]['cut'] = true;
        $state['hands'][2][0]['cut'] = true;
        $this->assertTrue($this->engine->isUnlocked($state, $card('cut_all')));

        // A mistake unlocks after_mistake cards for good, even once the Rewinder undoes the tick
        $state = $this->engine->dualCut($state, 1, 2, 1, 7);
        $state['detonator']['mistakes'] = 0;
        $this->assertTrue($this->engine->isUnlocked($state, $card('after_mistake')));
    }

    public function test_double_detector_cuts_whichever_of_the_two_wires_matches(): void
    {
        $state = $this->state([1 => [3, 7], 2 => [2, 7, 9], 3 => [3]], turn: 1);

        $state = $this->engine->doubleDetector($state, 1, 2, [2, 1], 7);

        $this->assertTrue($state['hands'][2][1]['cut']);
        $this->assertFalse($state['hands'][2][2]['cut']);
        $this->assertTrue($state['hands'][1][1]['cut']);
        $this->assertSame(0, $state['detonator']['mistakes']);
        $this->assertSame(['type' => 'double_detector', 'actor' => 1, 'target' => 2, 'indices' => [1, 2], 'value' => 7, 'success' => true, 'index' => 1], end($state['log']));
        $this->assertSame(2, $state['turn']);
        $this->assertTrue($state['detectors'][1]);
    }

    public function test_when_both_wires_match_the_left_one_is_cut(): void
    {
        $state = $this->state([1 => [7, 7], 2 => [7, 7, 9]], turn: 1);

        $state = $this->engine->doubleDetector($state, 1, 2, [0, 1], 7);

        $this->assertSame([true, false], [$state['hands'][2][0]['cut'], $state['hands'][2][1]['cut']]);
    }

    public function test_a_missed_double_detector_ticks_and_the_teammate_reveals_one_wire(): void
    {
        $state = $this->state([1 => [3, 7], 2 => [2, 'r6', 9], 3 => [3]], turn: 1);

        $state = $this->engine->doubleDetector($state, 1, 2, [0, 1], 7);

        // Nothing cut (not even the red wire), the detonator ticks, and the turn waits for player 2
        $this->assertSame([false, false], [$state['hands'][2][0]['cut'], $state['hands'][2][1]['cut']]);
        $this->assertSame('playing', $state['phase']);
        $this->assertSame(1, $state['detonator']['mistakes']);
        $this->assertSame(1, $state['turn']);
        $this->assertSame(['type' => 'detector_reveal', 'player' => 2, 'actor' => 1, 'indices' => [0, 1], 'value' => 7], $state['pending']);
        $this->assertArrayNotHasKey('actual', end($state['log']), 'the miss itself reveals nothing');

        // Everything else waits, and only player 2 may answer, with one of the two wires
        $this->assertRule('game.errors.waiting_for_reveal', fn () => $this->engine->dualCut($state, 1, 3, 0, 3));
        $this->assertRule('game.errors.nothing_to_reveal', fn () => $this->engine->revealForDetector($state, 3, 0));
        $this->assertRule('game.errors.detector_pick_one', fn () => $this->engine->revealForDetector($state, 2, 2));

        $state = $this->engine->revealForDetector($state, 2, 1);
        $this->assertSame('red', $state['hands'][2][1]['info']);
        $this->assertNull($state['pending']);
        $this->assertSame(2, $state['turn']);
        $this->assertSame(['type' => 'detector_reveal', 'player' => 2, 'index' => 1, 'value' => 'red'], end($state['log']));
    }

    public function test_the_double_detector_works_once_per_player_and_follows_the_usual_rules(): void
    {
        $state = $this->state([1 => [3, 7, 'y4'], 2 => [2, 5, 9], 3 => [3, 5]], turn: 1);
        $state['sequence'] = [3, 7];

        $this->assertRule('game.errors.not_own_wire', fn () => $this->engine->doubleDetector($state, 1, 1, [0, 1], 3));
        $this->assertRule('game.errors.detector_two_wires', fn () => $this->engine->doubleDetector($state, 1, 2, [1, 1], 3));
        $this->assertRule('game.errors.value_not_held', fn () => $this->engine->doubleDetector($state, 1, 2, [0, 1], 9));
        $this->assertRule('game.errors.value_not_held', fn () => $this->engine->doubleDetector($state, 1, 2, [0, 1], 'red'));
        $this->assertRule('game.errors.sequence_locked', fn () => $this->engine->doubleDetector($state, 1, 2, [0, 1], 7));

        $state = $this->engine->doubleDetector($state, 1, 2, [0, 1], 'yellow');
        $state = $this->engine->revealForDetector($state, 2, 0);
        $state['turn'] = 1;
        $this->assertRule('game.errors.detector_used', fn () => $this->engine->doubleDetector($state, 1, 3, [0, 1], 3));
    }

    public function test_the_stabilizer_also_absorbs_a_missed_double_detector(): void
    {
        $state = $this->unlocked($this->state([1 => [3, 7], 2 => [2, 9], 3 => [3]], turn: 1, equipment: ['stabilizer']));

        $state = $this->engine->useEquipment($state, 1, 'stabilizer');
        $state = $this->engine->doubleDetector($state, 1, 2, [0, 1], 7);

        $this->assertSame(0, $state['detonator']['mistakes']);
        $this->assertTrue(end($state['log'])['stabilized']);
        $this->assertNotNull($state['pending'], 'the teammate still reveals a wire');
    }

    public function test_a_missed_double_detector_can_blow_the_bomb(): void
    {
        $state = $this->state([1 => [3, 7], 2 => [2, 9]], turn: 1);
        $state['detonator']['mistakes'] = 3;

        $state = $this->engine->doubleDetector($state, 1, 2, [0, 1], 7);

        $this->assertSame('lost', $state['phase']);
        $this->assertNull($state['pending']);
    }

    public function test_state_survives_a_json_round_trip(): void
    {
        $state = json_decode(json_encode($this->engine->start([3, 7, 9], 7, 16)), true);

        foreach ([3, 7, 9] as $player) {
            $blue = array_search('blue', array_column($state['hands'][$player], 'color'), true);
            $state = $this->engine->placeInfoToken($state, $player, $blue);
        }

        $this->assertSame(7, $state['turn']);
        $this->assertNotEmpty($this->engine->heldValues($state, 7));
        $this->assertIsFloat(collect(array_merge(...array_values($state['hands'])))->firstWhere('color', 'red')['value']);
    }

    /**
     * @param  array<int, list<int|string>>  $hands  blue numbers, or 'y4' / 'r6' for yellow 4.1 / red 6.5
     * @param  list<string>  $equipment
     * @return array<string, mixed>
     */
    private function state(array $hands, string $phase = 'playing', ?int $turn = null, ?int $captain = null, array $equipment = []): array
    {
        $order = array_keys($hands);
        $wire = function (int|string $spec) {
            [$color, $value] = is_int($spec)
                ? ['blue', $spec]
                : [$spec[0] === 'y' ? 'yellow' : 'red', (int) substr($spec, 1) + ($spec[0] === 'y' ? 0.1 : 0.5)];

            return ['color' => $color, 'value' => $value, 'rank' => (int) round($value * 10), 'cut' => false, 'info' => null, 'revealed' => false];
        };

        return [
            'phase' => $phase,
            'mission' => 1,
            'sequence' => [],
            'order' => $order,
            'captain' => $captain ?? $order[0],
            'turn' => $phase === 'playing' ? $turn : null,
            'hands' => array_map(fn (array $specs) => array_map($wire, $specs), $hands),
            'candidates' => ['yellow' => [], 'red' => []],
            'info_placed' => array_fill_keys($order, $phase !== 'setup'),
            'detonator' => ['mistakes' => 0, 'limit' => 4, 'ticked' => 0],
            'equipment' => array_map(fn (string $id) => ['id' => $id, 'unlock' => config("bombbusters.equipment.{$id}.unlock"), 'rule' => 'cut_pair', 'used' => false], $equipment),
            'stabilized' => null,
            'labels' => [],
            'detectors' => array_fill_keys($order, false),
            'pending' => null,
            'log' => [],
        ];
    }

    /**
     * Meet every card's unlock condition: point them all at value 12 and add two cut blue 12s
     * to the last player's stand (appended, so existing indexes don't move).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function unlocked(array $state): array
    {
        $last = end($state['order']);
        $cutTwelve = ['color' => 'blue', 'value' => 12, 'rank' => 120, 'cut' => true, 'info' => null, 'revealed' => false];
        array_push($state['hands'][$last], $cutTwelve, $cutTwelve);

        foreach (array_column($state['equipment'], 'id') as $id) {
            $state = $this->withUnlock($state, $id, 12);
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function withUnlock(array $state, string $id, int $unlock): array
    {
        foreach ($state['equipment'] as $slot => $card) {
            if ($card['id'] === $id) {
                $state['equipment'][$slot]['unlock'] = $unlock;
            }
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<int> [detonator limit, yellow wires, red wires, equipment cards]
     */
    private function summary(array $state): array
    {
        $colors = array_count_values(array_column(array_merge(...array_values($state['hands'])), 'color'));

        return [$state['detonator']['limit'], $colors['yellow'] ?? 0, $colors['red'] ?? 0, count($state['equipment'])];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function assertSortedStands(array $state): void
    {
        foreach ($state['hands'] as $hand) {
            $ranks = array_column($hand, 'rank');
            $sorted = $ranks;
            sort($sorted);
            $this->assertSame($sorted, $ranks, 'Stands are sorted by value');
        }
    }

    private function assertRule(string $key, callable $move): void
    {
        try {
            $move();
        } catch (GameRuleException $exception) {
            $this->assertSame($key, $exception->key);

            return;
        }

        $this->fail("Expected rule violation {$key}");
    }
}
