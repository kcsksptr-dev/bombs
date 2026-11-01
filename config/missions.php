<?php

/*
|--------------------------------------------------------------------------
| Missions
|--------------------------------------------------------------------------
|
| The campaign, numbered from 1. Each mission is pure data, read by
| App\Game\GameEngine::start(); add or change missions here without touching code.
| Names and briefings live in lang/{en,hu}.json under "mission.{number}.name"
| and "mission.{number}.briefing" (a test checks every mission has both).
|
| This is an original campaign that follows the printed game's progression
| (blue only → yellow → sequences → red → everything tighter). To play the
| printed missions instead, transcribe them in the same format.
|
| detonator_bonus  added to the per-player-count limit in bombbusters.detonator
|                  (the result is never below 1)
| yellow / red     'pool' candidate values are drawn from 1..11 and shown to
|                  everyone; 'count' of them are secretly shuffled in. With
|                  count === pool the team knows exactly which are in play.
| sequence         kinds (blue numbers, or 'yellow') that must be finished in this
|                  order: nobody may cut a kind until every earlier kind in the
|                  list is fully cut. Kinds not listed are free. Never 'red'.
| equipment        'count' cards drawn at random from bombbusters.equipment, or an
|                  explicit 'cards' list; 'rule' says how they unlock:
|                    cut_pair       two blue wires of the card's number are cut (default)
|                    cut_all        every blue wire of the card's number is cut
|                    after_mistake  once the detonator has ticked at least once
|                    from_start     usable right away
|
*/

return [

    // Blue only: learn Dual Cut, Solo Cut and the detonator
    1 => [
        'detonator_bonus' => 2,
        'equipment' => ['count' => 2],
    ],

    2 => [
        'detonator_bonus' => 1,
        'equipment' => ['count' => 3],
    ],

    // Yellow wires: first known, then hidden among candidates
    3 => [
        'detonator_bonus' => 1,
        'yellow' => ['count' => 2, 'pool' => 2],
        'equipment' => ['count' => 3],
    ],

    4 => [
        'yellow' => ['count' => 2, 'pool' => 3],
        'equipment' => ['count' => 3],
    ],

    // Sequences: some numbers must be finished in order
    5 => [
        'detonator_bonus' => 1,
        'sequence' => [4, 8, 12],
        'equipment' => ['count' => 3],
    ],

    6 => [
        'yellow' => ['count' => 2, 'pool' => 3],
        'sequence' => ['yellow', 6],
        'equipment' => ['count' => 3],
    ],

    // Red wires: first known, then hidden among candidates
    7 => [
        'yellow' => ['count' => 2, 'pool' => 3],
        'red' => ['count' => 1, 'pool' => 1],
        'equipment' => ['count' => 3],
    ],

    8 => [
        'yellow' => ['count' => 2, 'pool' => 3],
        'red' => ['count' => 1, 'pool' => 2],
        'equipment' => ['count' => 3],
    ],

    // Equipment gets harder to earn
    9 => [
        'yellow' => ['count' => 2, 'pool' => 3],
        'red' => ['count' => 1, 'pool' => 2],
        'equipment' => ['count' => 4, 'rule' => 'cut_all'],
    ],

    10 => [
        'yellow' => ['count' => 3, 'pool' => 4],
        'red' => ['count' => 1, 'pool' => 2],
        'sequence' => [2, 5, 8, 11],
        'equipment' => ['count' => 3],
    ],

    11 => [
        'detonator_bonus' => -1,
        'yellow' => ['count' => 3, 'pool' => 4],
        'red' => ['count' => 1, 'pool' => 2],
        'equipment' => ['cards' => ['rewinder', 'stabilizer'], 'rule' => 'after_mistake'],
    ],

    12 => [
        'yellow' => ['count' => 2, 'pool' => 3],
        'red' => ['count' => 2, 'pool' => 3],
        'equipment' => ['count' => 3],
    ],

    13 => [
        'yellow' => ['count' => 3, 'pool' => 4],
        'red' => ['count' => 2, 'pool' => 3],
        'sequence' => ['yellow', 3, 7],
        'equipment' => ['count' => 3],
    ],

    14 => [
        'detonator_bonus' => -1,
        'yellow' => ['count' => 3, 'pool' => 4],
        'red' => ['count' => 2, 'pool' => 3],
        'equipment' => ['count' => 2, 'rule' => 'cut_all'],
    ],

    15 => [
        'detonator_bonus' => -1,
        'yellow' => ['count' => 4, 'pool' => 5],
        'red' => ['count' => 2, 'pool' => 4],
        'sequence' => [1, 6, 12],
        'equipment' => ['count' => 3],
    ],

    // The big one
    16 => [
        'detonator_bonus' => -1,
        'yellow' => ['count' => 4, 'pool' => 5],
        'red' => ['count' => 3, 'pool' => 4],
        'sequence' => ['yellow', 4, 9],
        'equipment' => ['count' => 2, 'rule' => 'cut_all'],
    ],

];
