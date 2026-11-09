<?php

namespace App\Game;

/**
 * Projects the authoritative state onto what one player may see. This is the only way game
 * state reaches a client: other players' wires are reduced to position + whatever is public
 * (cut or revealed wires face up, info tokens, equipment labels). Hidden wires don't even show
 * their colour.
 */
class GameView
{
    public function __construct(
        private readonly GameEngine $engine,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     * @param  array<int, array{name: string, seat: int}>  $players  user id => public lobby info
     * @return array<string, mixed>
     */
    public function for(array $state, int $viewer, array $players, int $undoSteps = 0): array
    {
        $isPlayer = array_key_exists($viewer, $state['hands']);
        $pending = $state['pending'] ?? null;
        // While a teammate chooses a wire to reveal for a Double Detector, nobody can act
        $myTurn = $state['phase'] === 'playing' && $state['turn'] === $viewer && $pending === null;

        return [
            'phase' => $state['phase'],
            'mission' => $state['mission'],
            'sequence' => $this->engine->sequenceProgress($state),
            'turn' => $state['turn'],
            'captain' => $state['captain'],
            'detonator' => $state['detonator'],
            'candidates' => $state['candidates'],
            'stands' => array_map(fn (int $userId) => [
                'id' => $userId,
                'name' => $players[$userId]['name'] ?? '?',
                'seat' => $players[$userId]['seat'] ?? null,
                'isMe' => $userId === $viewer,
                'needsInfoToken' => $this->engine->needsInfoToken($state, $userId),
                'detectorUsed' => (bool) ($state['detectors'][$userId] ?? true),
                'labels' => array_values(array_map(
                    fn (array $label) => ['index' => $label['index'], 'equal' => $label['equal']],
                    array_filter($state['labels'], fn (array $label) => $label['player'] === $userId),
                )),
                'wires' => array_map(function (array $wire) use ($userId, $viewer) {
                    // A wire is visible to its owner, or to everyone once it's cut or revealed
                    $visible = $userId === $viewer || $wire['cut'];

                    return [
                        'cut' => $wire['cut'],
                        'revealed' => $wire['revealed'],
                        'info' => $wire['info'],
                        'color' => $visible ? $wire['color'] : null,
                        'value' => $visible ? $wire['value'] : null,
                    ];
                }, $state['hands'][$userId]),
            ], $state['order']),
            'cutBoard' => $this->cutBoard($state),
            'equipment' => array_map(fn (array $card) => [
                'id' => $card['id'],
                'unlock' => $card['unlock'],
                'used' => $card['used'],
                'rule' => $card['rule'] ?? 'cut_pair',
                'unlocked' => $this->engine->isUnlocked($state, $card),
            ], $state['equipment']),
            'stabilized' => $state['stabilized'],
            // Public: who used the detector on whose two wires, and what they named
            'pending' => $pending,
            'hasNextMission' => config('missions.'.($state['mission'] + 1)) !== null,
            'me' => [
                'isPlayer' => $isPlayer,
                'myTurn' => $myTurn,
                'needsInfoToken' => $this->engine->needsInfoToken($state, $viewer),
                'heldValues' => $myTurn ? $this->engine->heldValues($state, $viewer) : [],
                // Held kinds a sequence currently forbids naming (shown locked in the picker)
                'lockedValues' => $myTurn ? array_values(array_diff($this->engine->heldValues($state, $viewer), $this->engine->allowedHeldValues($state, $viewer))) : [],
                'mustPass' => $myTurn && $this->engine->mustPass($state, $viewer),
                'soloCutValues' => $myTurn ? $this->engine->soloCutValues($state, $viewer) : [],
                'canRevealRed' => $myTurn && $this->engine->canRevealRed($state, $viewer),
                'canUseDetector' => $myTurn && ! ($state['detectors'][$viewer] ?? true) && $this->engine->allowedHeldValues($state, $viewer) !== [],
                'mustRevealForDetector' => ($pending['type'] ?? null) === 'detector_reveal' && $pending['player'] === $viewer,
                // Walkie-Talkies: a teammate asked me to pick a wire to swap with theirs
                'mustAnswerWalkie' => ($pending['type'] ?? null) === 'walkie' && $pending['player'] === $viewer,
            ],
            // Everything in the log is public information by construction
            'log' => $state['log'],
            'logLength' => count($state['log']),
            // How many moves the host can take back
            'undoSteps' => $undoSteps,
        ];
    }

    /**
     * Cut counts per kind: the shared board in the middle of the table. Yellow/red totals are
     * only what's public (the number in play), never which values.
     *
     * @param  array<string, mixed>  $state
     * @return list<array{kind: int|string, cut: int, total: int}>
     */
    private function cutBoard(array $state): array
    {
        $board = [];

        foreach (range(1, config('bombbusters.blue_values')) as $value) {
            $board[$value] = ['kind' => $value, 'cut' => 0, 'total' => config('bombbusters.blue_copies')];
        }

        $board['yellow'] = ['kind' => 'yellow', 'cut' => 0, 'total' => 0];
        $board['red'] = ['kind' => 'red', 'cut' => 0, 'total' => 0];

        foreach ($state['hands'] as $hand) {
            foreach ($hand as $wire) {
                $kind = $this->engine->kindOf($wire);

                if ($wire['color'] !== 'blue') {
                    $board[$kind]['total']++;
                }

                if ($wire['cut']) {
                    $board[$kind]['cut']++;
                }
            }
        }

        return array_values(array_filter($board, fn (array $slot) => $slot['total'] > 0));
    }
}
