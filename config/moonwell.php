<?php

/*
|--------------------------------------------------------------------------
| Moonwell Garden
|--------------------------------------------------------------------------
|
| An original tile-drafting race (rules: App\Games\Moonwell\MoonwellEngine).
| Tiles lie in a ring around the well; the player furthest back on the time
| path takes one of the next `choices` tiles after the lantern, pays its moon
| cost in time and plants it next to their garden. Every wish that comes true
| gets one of their fireflies; whoever places all of them first wins.
|
| The numbers for 6–8 players are this edition's own scaling. Tune after
| playtests.
|
*/

return [

    'min_players' => 2,

    'max_players' => 8,

    // Each colour has its own shape in the UI (resources/js/moonwell/art.js), so it plays colour-blind
    'colors' => ['blue', 'orange', 'pink', 'green'],

    // Moon costs printed on the tiles, dealt evenly across the bag
    'costs' => [1, 2, 3, 4, 5, 6, 7],

    /*
    | Per table size: ring slots, how many tiles after the lantern may be taken,
    | tiles in the bag (ring included), fireflies per player. The ring refills
    | from the bag when only `choices` tiles or fewer are left in it.
    */
    'tables' => [
        2 => ['slots' => 12, 'choices' => 3, 'tiles' => 80, 'fireflies' => 22],
        3 => ['slots' => 12, 'choices' => 3, 'tiles' => 84, 'fireflies' => 22],
        4 => ['slots' => 12, 'choices' => 3, 'tiles' => 100, 'fireflies' => 20],
        5 => ['slots' => 12, 'choices' => 3, 'tiles' => 112, 'fireflies' => 20],
        6 => ['slots' => 15, 'choices' => 4, 'tiles' => 120, 'fireflies' => 18],
        7 => ['slots' => 15, 'choices' => 4, 'tiles' => 136, 'fireflies' => 17],
        8 => ['slots' => 15, 'choices' => 4, 'tiles' => 140, 'fireflies' => 16],
    ],

    /*
    | Wish layouts by moon cost: each option is a list of wishes, each number is
    | how many shapes that wish asks for (1–3). A wish comes true when the tile's
    | four neighbours include all its shapes. Cheap tiles ask for a lot from few
    | wishes; expensive ones carry more, easier wishes. One option is picked per tile.
    */
    'wishes' => [
        1 => [[3], [2]],
        2 => [[3], [2, 3]],
        3 => [[2, 2], [1, 3]],
        4 => [[1, 2], [2, 2]],
        5 => [[1, 2], [1, 1, 2]],
        6 => [[1, 1, 2], [1, 2, 2]],
        7 => [[1, 1, 1], [1, 1, 2], [1, 1, 1, 2]],
    ],

];
