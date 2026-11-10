<?php

use App\Games\BombBusters\BombBusters;
use App\Games\Moonwell\Moonwell;

return [

    /*
    |--------------------------------------------------------------------------
    | Room size
    |--------------------------------------------------------------------------
    |
    | Limits for every room, whatever the game. Each game narrows this with its
    | own minPlayers() / maxPlayers() once the host has picked it.
    |
    */

    'min_players' => 2,

    'max_players' => 8,

    'default_capacity' => 4,

    /*
    |--------------------------------------------------------------------------
    | Games
    |--------------------------------------------------------------------------
    |
    | Every game the room's picker offers, keyed by what rooms.game_key stores.
    | Each class implements App\Games\GameDefinition. Order is picker order.
    |
    */

    'available' => [
        'bombbusters' => BombBusters::class,
        'moonwell' => Moonwell::class,
    ],

];
