<?php

namespace App\Game;

use Random\Randomizer;

/**
 * The rules of a mission, as pure functions over a plain-array state:
 * every method takes the current state and returns the next one, or throws GameRuleException.
 *
 * Wires:
 *   blue    value 1..12, blue_copies of each. Cut with a Dual Cut naming the number.
 *   yellow  value x.1 (sorts right after blue x). Cut with a Dual Cut naming "yellow".
 *   red     value x.5. Never cut: hitting one blows the bomb. Revealed by its owner once only red is left.
 *   A wire is ['color', 'value', 'rank' (value × 10, for sorting), 'cut', 'info', 'revealed'].
 *   Its "kind" is the blue number (int) or 'yellow' / 'red': what a player names when cutting.
 *
 * State shape:
 *   phase        'setup' | 'playing' | 'won' | 'lost'
 *   mission      number from config('missions')
 *   sequence     kinds that must be finished in this order (see config/missions.php)
 *   order        user ids in seat order (turns go round this list)
 *   captain      user id who deals first and takes the first turn
 *   turn         user id whose turn it is (null outside 'playing')
 *   hands        [userId => list of wires], sorted by rank; positions never change,
 *                cut wires stay in place face up
 *   candidates   ['yellow' => list<float>, 'red' => list<float>] public: values that *might* be in play
 *   info_placed  [userId => bool] whether the player has made their one setup reveal
 *   detonator    ['mistakes' => int, 'limit' => int, 'ticked' => int]; the bomb explodes when mistakes
 *                reach limit; 'ticked' counts every mistake ever (the Rewinder doesn't undo it)
 *   equipment    list of ['id', 'unlock', 'rule', 'used'] dealt for this mission
 *   stabilized   user id protected by the Stabilizer for the rest of their turn, or null
 *   labels       list of ['player', 'index', 'equal'] public marks between wire index and index + 1
 *   detectors    [userId => bool] whether the player has used their one Double Detector
 *   pending      null, or ['type' => 'detector_reveal', 'player', 'actor', 'indices', 'value']: a missed
 *                Double Detector waits for the targeted player to reveal one of the two wires
 *   log          public history entries (see the log() calls), rendered and translated by the client
 */
class GameEngine
{
    public function __construct(
        private readonly Randomizer $randomizer = new Randomizer,
    ) {}

    /**
     * The mission's settings with defaults filled in.
     *
     * @return array{detonator_bonus: int, yellow: array{count: int, pool: int}, red: array{count: int, pool: int}, sequence: list<int|string>, equipment: array{count?: int, cards?: list<string>, rule: string}}
     */
    public static function mission(int $number): array
    {
        $mission = config("missions.{$number}") ?? throw new GameRuleException('lobby.errors.invalid_mission');

        return [
            'detonator_bonus' => $mission['detonator_bonus'] ?? 0,
            'yellow' => ($mission['yellow'] ?? []) + ['count' => 0, 'pool' => 0],
            'red' => ($mission['red'] ?? []) + ['count' => 0, 'pool' => 0],
            'sequence' => $mission['sequence'] ?? [],
            'equipment' => ($mission['equipment'] ?? []) + ['count' => 0, 'rule' => 'cut_pair'],
        ];
    }

    /**
     * Shuffle the mission's wires and deal them round the table starting with the Captain.
     *
     * @param  list<int>  $order  user ids in seat order
     * @return array<string, mixed>
     */
    public function start(array $order, int $captain, int $mission = 1): array
    {
        $level = self::mission($mission);

        $wires = [];

        foreach (range(1, config('bombbusters.blue_values')) as $value) {
            for ($copy = 0; $copy < config('bombbusters.blue_copies'); $copy++) {
                $wires[] = $this->makeWire('blue', $value);
            }
        }

        $candidates = [];

        foreach (['yellow' => 0.1, 'red' => 0.5] as $color => $offset) {
            $pool = $this->draw(range(1, 11), $level[$color]['pool']);
            $candidates[$color] = array_map(fn (int $base) => $base + $offset, $pool);
            sort($candidates[$color]);

            foreach ($this->draw($pool, $level[$color]['count']) as $base) {
                $wires[] = $this->makeWire($color, $base + $offset);
            }
        }

        $wires = $this->randomizer->shuffleArray($wires);
        $dealOrder = $this->rotateTo($order, $captain);
        $hands = array_fill_keys($order, []);

        foreach ($wires as $index => $wire) {
            $hands[$dealOrder[$index % count($dealOrder)]][] = $wire;
        }

        foreach ($hands as $userId => $hand) {
            usort($hand, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);
            $hands[$userId] = $hand;
        }

        $cards = $level['equipment']['cards'] ?? $this->draw(array_keys(config('bombbusters.equipment')), $level['equipment']['count']);
        $equipment = array_map(fn (string $id) => [
            'id' => $id,
            'unlock' => config("bombbusters.equipment.{$id}.unlock"),
            'rule' => $level['equipment']['rule'],
            'used' => false,
        ], $cards);
        usort($equipment, fn (array $a, array $b) => $a['unlock'] <=> $b['unlock']);

        $state = [
            'phase' => 'setup',
            'mission' => $mission,
            'sequence' => $level['sequence'],
            'order' => array_values($order),
            'captain' => $captain,
            'turn' => null,
            'hands' => $hands,
            'candidates' => $candidates,
            'info_placed' => array_fill_keys($order, false),
            'detonator' => [
                'mistakes' => 0,
                'limit' => max(1, $this->detonatorLimit(count($order)) + $level['detonator_bonus']),
                'ticked' => 0,
            ],
            'equipment' => $equipment,
            'stabilized' => null,
            'labels' => [],
            'detectors' => array_fill_keys($order, false),
            'pending' => null,
            'log' => [],
        ];

        return $state;
    }

    /**
     * Setup: each player reveals exactly one of their own blue wires with an info token.
     * Once everyone has, the Captain takes the first turn.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function placeInfoToken(array $state, int $userId, int $index): array
    {
        $this->assertPhase($state, 'setup');
        $this->assertPlayer($state, $userId);

        if ($state['info_placed'][$userId]) {
            throw new GameRuleException('game.errors.info_already_placed');
        }

        $state = $this->revealWithInfoToken($state, $userId, $index);
        $state['info_placed'][$userId] = true;

        return in_array(false, $state['info_placed'], true) ? $state : $this->beginPlay($state);
    }

    /**
     * Whether this player still has to make their setup reveal.
     *
     * @param  array<string, mixed>  $state
     */
    public function needsInfoToken(array $state, int $userId): bool
    {
        return $state['phase'] === 'setup' && ($state['info_placed'][$userId] ?? true) === false;
    }

    /**
     * Dual Cut: point at a teammate's wire and name a kind you hold yourself (a blue number or 'yellow').
     * Right: both wires are cut. A red wire: the bomb explodes. Otherwise the detonator advances
     * and an info token shows what the wire really is.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function dualCut(array $state, int $actor, int $target, int $index, int|string $kind): array
    {
        $this->assertTurn($state, $actor);

        if ($actor === $target) {
            throw new GameRuleException('game.errors.not_own_wire');
        }

        $this->assertPlayer($state, $target);
        $wire = $this->wire($state, $target, $index);
        $kind = $this->normalizeKind($kind);

        if ($kind === 'red') {
            throw new GameRuleException('game.errors.value_not_held');
        }

        $own = $this->firstUncut($state, $actor, $kind) ?? throw new GameRuleException('game.errors.value_not_held');
        // Checked against what the player *named*, so a refusal never hints at the target wire
        $this->assertSequenceAllows($state, $kind);
        $entry = ['type' => 'dual_cut', 'actor' => $actor, 'target' => $target, 'index' => $index, 'value' => $kind];

        if ($wire['color'] === 'red') {
            $state['hands'][$target][$index]['cut'] = true;
            $state = $this->log($state, [...$entry, 'success' => false, 'actual' => 'red']);

            return $this->finish($state, 'lost');
        }

        if ($this->kindOf($wire) === $kind) {
            $state['hands'][$target][$index]['cut'] = true;
            $state['hands'][$actor][$own]['cut'] = true;
            $state = $this->log($state, [...$entry, 'success' => true]);

            return $this->endTurn($state);
        }

        $state['hands'][$target][$index]['info'] = $this->kindOf($wire);
        $stabilized = $state['stabilized'] === $actor;

        if (! $stabilized) {
            $state['detonator']['mistakes']++;
            $state['detonator']['ticked'] = ($state['detonator']['ticked'] ?? 0) + 1;
        }

        $state = $this->log($state, [...$entry, 'success' => false, 'actual' => $this->kindOf($wire), 'stabilized' => $stabilized]);

        if ($state['detonator']['mistakes'] >= $state['detonator']['limit']) {
            return $this->finish($state, 'lost');
        }

        return $this->endTurn($state);
    }

    /**
     * Solo Cut: if every remaining uncut wire of a kind (blue number or yellow) is on your own stand, cut them all.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function soloCut(array $state, int $actor, int|string $kind): array
    {
        $this->assertTurn($state, $actor);
        $kind = $this->normalizeKind($kind);

        $this->assertSequenceAllows($state, $kind);

        if (! in_array($kind, $this->soloCutValues($state, $actor), true)) {
            throw new GameRuleException('game.errors.solo_cut_not_allowed', ['value' => $kind]);
        }

        $count = 0;

        foreach ($state['hands'][$actor] as $index => $wire) {
            if (! $wire['cut'] && $this->kindOf($wire) === $kind) {
                $state['hands'][$actor][$index]['cut'] = true;
                $count++;
            }
        }

        $state = $this->log($state, ['type' => 'solo_cut', 'actor' => $actor, 'value' => $kind, 'count' => $count]);

        return $this->endTurn($state);
    }

    /**
     * When only red wires are left on your stand, reveal them: they're done.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function revealRed(array $state, int $actor): array
    {
        $this->assertTurn($state, $actor);

        if (! $this->canRevealRed($state, $actor)) {
            throw new GameRuleException('game.errors.reveal_red_not_allowed');
        }

        $count = 0;

        foreach ($state['hands'][$actor] as $index => $wire) {
            if (! $wire['cut']) {
                $state['hands'][$actor][$index]['cut'] = true;
                $state['hands'][$actor][$index]['revealed'] = true;
                $count++;
            }
        }

        $state = $this->log($state, ['type' => 'reveal_red', 'actor' => $actor, 'count' => $count]);

        return $this->endTurn($state);
    }

    /**
     * Skip your turn. Only allowed when you have no legal move at all, which a sequence can cause
     * (e.g. you hold only 8s while 4s must be finished first and they're all on other stands).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function pass(array $state, int $actor): array
    {
        $this->assertTurn($state, $actor);

        if (! $this->mustPass($state, $actor)) {
            throw new GameRuleException('game.errors.pass_not_allowed');
        }

        $state = $this->log($state, ['type' => 'pass', 'actor' => $actor]);

        return $this->endTurn($state);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function mustPass(array $state, int $userId): bool
    {
        return $this->allowedHeldValues($state, $userId) === []
            && $this->soloCutValues($state, $userId) === []
            && ! $this->canRevealRed($state, $userId);
    }

    /**
     * Held kinds the sequence currently lets this player name.
     *
     * @param  array<string, mixed>  $state
     * @return list<int|string>
     */
    public function allowedHeldValues(array $state, int $userId): array
    {
        return array_values(array_filter($this->heldValues($state, $userId), fn ($kind) => $this->sequenceAllows($state, $kind)));
    }

    /**
     * The sequence with progress: which steps are finished and which one is open now.
     *
     * @param  array<string, mixed>  $state
     * @return list<array{kind: int|string, done: bool, open: bool}>
     */
    public function sequenceProgress(array $state): array
    {
        $remaining = $this->uncutCounts(array_merge(...array_values($state['hands'])));
        $steps = [];
        $blocked = false;

        foreach ($state['sequence'] as $kind) {
            $done = ($remaining[$kind] ?? 0) === 0;
            $steps[] = ['kind' => $kind, 'done' => $done, 'open' => ! $done && ! $blocked];
            $blocked = $blocked || ! $done;
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function sequenceAllows(array $state, int|string $kind): bool
    {
        foreach ($this->sequenceProgress($state) as $step) {
            if ($step['kind'] === $kind) {
                return $step['done'] || $step['open'];
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function assertSequenceAllows(array $state, int|string $kind): void
    {
        if ($this->sequenceAllows($state, $kind)) {
            return;
        }

        $blocking = collect($this->sequenceProgress($state))->first(fn (array $step) => $step['open']);

        throw new GameRuleException('game.errors.sequence_locked', ['value' => $kind, 'first' => $blocking['kind']]);
    }

    /**
     * Double Detector (each player's one-use ability): point at two wires on one teammate's stand and
     * name a kind you hold. If either matches, it is cut along with one of yours (the left one if both
     * match). If neither does, the detonator ticks and the teammate must reveal one of the two wires
     * of their choice (see revealForDetector) before the turn passes. Nothing is cut on a miss, so a
     * red wire among the two doesn't explode.
     *
     * @param  array<string, mixed>  $state
     * @param  array{int, int}  $indices
     * @return array<string, mixed>
     */
    public function doubleDetector(array $state, int $actor, int $target, array $indices, int|string $kind): array
    {
        $this->assertTurn($state, $actor);

        if ($state['detectors'][$actor] ?? true) {
            throw new GameRuleException('game.errors.detector_used');
        }

        if ($actor === $target) {
            throw new GameRuleException('game.errors.not_own_wire');
        }

        $this->assertPlayer($state, $target);
        $indices = array_values(array_map('intval', $indices));

        if (count($indices) !== 2 || $indices[0] === $indices[1]) {
            throw new GameRuleException('game.errors.detector_two_wires');
        }

        sort($indices);
        $wires = array_map(fn (int $index) => $this->wire($state, $target, $index), $indices);
        $kind = $this->normalizeKind($kind);

        if ($kind === 'red') {
            throw new GameRuleException('game.errors.value_not_held');
        }

        $own = $this->firstUncut($state, $actor, $kind) ?? throw new GameRuleException('game.errors.value_not_held');
        $this->assertSequenceAllows($state, $kind);

        $state['detectors'][$actor] = true;
        $entry = ['type' => 'double_detector', 'actor' => $actor, 'target' => $target, 'indices' => $indices, 'value' => $kind];

        foreach ($wires as $slot => $wire) {
            if ($this->kindOf($wire) === $kind) {
                $state['hands'][$target][$indices[$slot]]['cut'] = true;
                $state['hands'][$actor][$own]['cut'] = true;
                $state = $this->log($state, [...$entry, 'success' => true, 'index' => $indices[$slot]]);

                return $this->endTurn($state);
            }
        }

        $stabilized = $state['stabilized'] === $actor;

        if (! $stabilized) {
            $state['detonator']['mistakes']++;
            $state['detonator']['ticked'] = ($state['detonator']['ticked'] ?? 0) + 1;
        }

        // The log says only that both missed; which wire gets revealed is the teammate's choice
        $state = $this->log($state, [...$entry, 'success' => false, 'stabilized' => $stabilized]);

        if ($state['detonator']['mistakes'] >= $state['detonator']['limit']) {
            return $this->finish($state, 'lost');
        }

        $state['pending'] = ['type' => 'detector_reveal', 'player' => $target, 'actor' => $actor, 'indices' => $indices, 'value' => $kind];

        return $state;
    }

    /**
     * After a missed Double Detector, the targeted player reveals one of the two wires with an info
     * token showing what it really is (number, yellow or red). Then the actor's turn ends.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function revealForDetector(array $state, int $player, int $index): array
    {
        $this->assertPhase($state, 'playing');
        $pending = $state['pending'];

        if (($pending['type'] ?? null) !== 'detector_reveal' || $pending['player'] !== $player) {
            throw new GameRuleException('game.errors.nothing_to_reveal');
        }

        if (! in_array($index, $pending['indices'], true)) {
            throw new GameRuleException('game.errors.detector_pick_one');
        }

        $kind = $this->kindOf($state['hands'][$player][$index]);
        $state['hands'][$player][$index]['info'] = $kind;
        $state['pending'] = null;
        $state = $this->log($state, ['type' => 'detector_reveal', 'player' => $player, 'index' => $index, 'value' => $kind]);

        return $this->endTurn($state);
    }

    /**
     * Use an unlocked equipment card. Doesn't end the turn.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $params  card-specific: 'index' (label, post_it) or 'value' (radar)
     * @return array<string, mixed>
     */
    public function useEquipment(array $state, int $actor, string $id, array $params = []): array
    {
        $this->assertTurn($state, $actor);

        $slot = array_search($id, array_column($state['equipment'], 'id'), true);

        if ($slot === false) {
            throw new GameRuleException('game.errors.equipment_not_in_play');
        }

        if ($state['equipment'][$slot]['used']) {
            throw new GameRuleException('game.errors.equipment_used');
        }

        if (! $this->isUnlocked($state, $state['equipment'][$slot])) {
            $card = $state['equipment'][$slot];

            throw new GameRuleException('game.errors.equipment_locked_'.($card['rule'] ?? 'cut_pair'), ['value' => $card['unlock']]);
        }

        $entry = ['type' => 'equipment', 'id' => $id, 'actor' => $actor];

        switch ($id) {
            case 'rewinder':
                $state['detonator']['mistakes'] = max(0, $state['detonator']['mistakes'] - 1);
                break;

            case 'stabilizer':
                $state['stabilized'] = $actor;
                break;

            case 'post_it':
                $index = (int) ($params['index'] ?? -1);
                $state = $this->revealWithInfoToken($state, $actor, $index);
                $entry += ['index' => $index, 'value' => $state['hands'][$actor][$index]['value']];
                break;

            case 'label':
                $index = (int) ($params['index'] ?? -1);
                $left = $this->wire($state, $actor, $index);
                $right = $this->wire($state, $actor, $index + 1);
                $equal = $this->kindOf($left) === $this->kindOf($right);
                $state['labels'][] = ['player' => $actor, 'index' => $index, 'equal' => $equal];
                $entry += ['index' => $index, 'equal' => $equal];
                break;

            case 'radar':
                $value = (int) ($params['value'] ?? 0);

                if ($value < 1 || $value > config('bombbusters.blue_values')) {
                    throw new GameRuleException('game.errors.no_such_value');
                }

                $entry += ['value' => $value, 'holders' => array_values(array_filter(
                    $state['order'],
                    fn (int $userId) => $this->firstUncut($state, $userId, $value) !== null,
                ))];
                break;
        }

        $state['equipment'][$slot]['used'] = true;

        return $this->log($state, $entry);
    }

    /**
     * Kinds the player could Solo Cut right now.
     *
     * @param  array<string, mixed>  $state
     * @return list<int|string>
     */
    public function soloCutValues(array $state, int $userId): array
    {
        $mine = $this->uncutCounts($state['hands'][$userId] ?? []);
        $everyone = $this->uncutCounts(array_merge(...array_values($state['hands'])));
        unset($mine['red']);

        return array_values(array_filter(
            array_keys($mine),
            fn (int|string $kind) => $mine[$kind] === $everyone[$kind] && $this->sequenceAllows($state, $kind),
        ));
    }

    /**
     * Kinds the player still holds uncut and may name in a Dual Cut (blue numbers, 'yellow').
     *
     * @param  array<string, mixed>  $state
     * @return list<int|string>
     */
    public function heldValues(array $state, int $userId): array
    {
        return array_values(array_filter(array_keys($this->uncutCounts($state['hands'][$userId] ?? [])), fn ($kind) => $kind !== 'red'));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function canRevealRed(array $state, int $userId): bool
    {
        $uncut = array_filter($state['hands'][$userId] ?? [], fn (array $wire) => ! $wire['cut']);

        return $uncut !== [] && array_values(array_unique(array_column($uncut, 'color'))) === ['red'];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array{id: string, unlock: int, rule?: string, used: bool}  $card
     */
    public function isUnlocked(array $state, array $card): bool
    {
        $rule = $card['rule'] ?? 'cut_pair';

        if ($rule === 'from_start') {
            return true;
        }

        if ($rule === 'after_mistake') {
            return ($state['detonator']['ticked'] ?? 0) > 0;
        }

        $cut = 0;

        foreach ($state['hands'] as $hand) {
            foreach ($hand as $wire) {
                if ($wire['cut'] && $wire['color'] === 'blue' && $wire['value'] === $card['unlock']) {
                    $cut++;
                }
            }
        }

        return $cut >= ($rule === 'cut_all' ? config('bombbusters.blue_copies') : 2);
    }

    public function detonatorLimit(int $players): int
    {
        $limits = config('bombbusters.detonator');
        $counts = array_keys($limits);

        return $limits[min(max($players, min($counts)), max($counts))];
    }

    /**
     * What a player names to cut this wire: its blue number, or 'yellow' / 'red'.
     *
     * @param  array{color: string, value: int|float}  $wire
     */
    public function kindOf(array $wire): int|string
    {
        return $wire['color'] === 'blue' ? (int) $wire['value'] : $wire['color'];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function revealWithInfoToken(array $state, int $userId, int $index): array
    {
        $wire = $this->wire($state, $userId, $index);

        if ($wire['color'] !== 'blue') {
            throw new GameRuleException('game.errors.info_blue_only');
        }

        if ($wire['info'] !== null) {
            throw new GameRuleException('game.errors.wire_has_info');
        }

        $state['hands'][$userId][$index]['info'] = $wire['value'];

        if ($state['phase'] === 'setup') {
            $state = $this->log($state, ['type' => 'info', 'player' => $userId, 'index' => $index, 'value' => $wire['value']]);
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function endTurn(array $state): array
    {
        $state['stabilized'] = null;

        if ($this->allCut($state)) {
            return $this->finish($state, 'won');
        }

        // Next player round the table who still has wires; finished stands are skipped
        $order = $state['order'];
        $position = array_search($state['turn'], $order, true);

        for ($step = 1; $step <= count($order); $step++) {
            $next = $order[($position + $step) % count($order)];

            if ($this->hasUncut($state, $next)) {
                $state['turn'] = $next;
                break;
            }
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function beginPlay(array $state): array
    {
        $state['phase'] = 'playing';
        $state['turn'] = $state['captain'];

        return $this->log($state, ['type' => 'begin', 'player' => $state['captain']]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function finish(array $state, string $result): array
    {
        $state['phase'] = $result;
        $state['turn'] = null;
        $state['stabilized'] = null;
        $state['pending'] = null;

        return $this->log($state, ['type' => $result]);
    }

    /**
     * Notes on a restored state that a move was taken back, so the whole table sees it happened.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function markUndone(array $state, int $userId): array
    {
        return $this->log($state, ['type' => 'undo', 'actor' => $userId]);
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

    /**
     * @return array<string, mixed>
     */
    private function makeWire(string $color, int|float $value): array
    {
        return [
            'color' => $color,
            'value' => $value,
            'rank' => (int) round($value * 10),
            'cut' => false,
            'info' => null,
            'revealed' => false,
        ];
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private function draw(array $items, int $count): array
    {
        return $count > 0 ? array_slice($this->randomizer->shuffleArray($items), 0, $count) : [];
    }

    private function normalizeKind(int|string $kind): int|string
    {
        return is_numeric($kind) ? (int) $kind : $kind;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function wire(array $state, int $userId, int $index): array
    {
        $wire = $state['hands'][$userId][$index] ?? throw new GameRuleException('game.errors.no_such_wire');

        if ($wire['cut']) {
            throw new GameRuleException('game.errors.wire_already_cut');
        }

        return $wire;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function firstUncut(array $state, int $userId, int|string $kind): ?int
    {
        foreach ($state['hands'][$userId] as $index => $wire) {
            if (! $wire['cut'] && $this->kindOf($wire) === $kind) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $wires
     * @return array<int|string, int> kind => uncut count, blue numbers first in order, then yellow, red
     */
    private function uncutCounts(array $wires): array
    {
        $counts = [];

        foreach ($wires as $wire) {
            if (! $wire['cut']) {
                $kind = $this->kindOf($wire);
                $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            }
        }

        uksort($counts, fn ($a, $b) => [is_string($a), $a] <=> [is_string($b), $b]);

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function hasUncut(array $state, int $userId): bool
    {
        return in_array(false, array_column($state['hands'][$userId], 'cut'), true);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function allCut(array $state): bool
    {
        foreach ($state['order'] as $userId) {
            if ($this->hasUncut($state, $userId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function assertPhase(array $state, string $phase): void
    {
        if ($state['phase'] !== $phase) {
            throw new GameRuleException($state['phase'] === 'setup' ? 'game.errors.setup_not_finished' : 'game.errors.wrong_phase');
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function assertPlayer(array $state, int $userId): void
    {
        if (! array_key_exists($userId, $state['hands'])) {
            throw new GameRuleException('game.errors.not_a_player');
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function assertTurn(array $state, int $userId): void
    {
        $this->assertPhase($state, 'playing');

        if ($state['turn'] !== $userId) {
            throw new GameRuleException('game.errors.not_your_turn');
        }

        // Nothing else happens while a teammate chooses which wire to reveal for a Double Detector
        if (($state['pending'] ?? null) !== null) {
            throw new GameRuleException('game.errors.waiting_for_reveal');
        }
    }

    /**
     * @param  list<int>  $order
     * @return list<int>
     */
    private function rotateTo(array $order, int $first): array
    {
        $position = array_search($first, $order, true);

        return array_merge(array_slice($order, $position), array_slice($order, 0, $position));
    }
}
