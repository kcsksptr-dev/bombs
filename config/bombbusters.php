<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Player count
    |--------------------------------------------------------------------------
    |
    | The printed game supports up to 5 players; this edition scales the wire
    | distribution so tables of up to 8 can play.
    |
    */

    'min_players' => 2,

    'max_players' => 8,

    'default_capacity' => 4,

    /*
    |--------------------------------------------------------------------------
    | Wires
    |--------------------------------------------------------------------------
    |
    | Blue wires are numbered 1..blue_values with blue_copies of each (48 in the
    | printed game). All of them are dealt out as evenly as possible, starting
    | with the Captain, so at 7 players some stands hold one wire more.
    |
    */

    'blue_values' => 12,

    'blue_copies' => 4,

    /*
    |--------------------------------------------------------------------------
    | Detonator
    |--------------------------------------------------------------------------
    |
    | Number of failed cuts that sets the bomb off, by player count. Bigger
    | tables hold fewer wires each (less information per hand), so this
    | edition gives 6+ players extra room for mistakes. Each mission adds its
    | own detonator_bonus (config/missions.php). Tune after playtests.
    |
    */

    'detonator' => [
        2 => 4,
        3 => 4,
        4 => 4,
        5 => 4,
        6 => 5,
        7 => 5,
        8 => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Equipment
    |--------------------------------------------------------------------------
    |
    | Shared team gadgets, each usable once, by the player whose turn it is;
    | using one doesn't end the turn. 'unlock' is the card's number, used by the
    | cut_pair / cut_all unlock rules (see config/missions.php). Effects are
    | implemented in App\Game\GameEngine::useEquipment().
    |
    */

    'equipment' => [
        'label' => ['unlock' => 2],      // mark two neighbouring wires of yours as equal / different
        'post_it' => ['unlock' => 4],    // put an extra info token on one of your blue wires
        'radar' => ['unlock' => 5],      // name a value: everyone shows whether they still hold it
        'stabilizer' => ['unlock' => 7], // your next wrong cut this turn doesn't tick the detonator
        'rewinder' => ['unlock' => 10],  // turn the detonator back by one
    ],

    /*
    |--------------------------------------------------------------------------
    | Room codes
    |--------------------------------------------------------------------------
    |
    | Short codes players type on their phones. Ambiguous letters (I, O) are
    | left out so codes can be read aloud across a table.
    |
    */

    'room_code_length' => 4,

    'room_code_alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZ',

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    */

    'locales' => ['en', 'hu'],

    /*
    |--------------------------------------------------------------------------
    | LAN address
    |--------------------------------------------------------------------------
    |
    | This machine's address on the local network (passed in by make). Used for
    | join links and QR codes so phones get a URL they can actually open.
    |
    */

    'lan_ip' => env('LAN_IP'),

    /*
    |--------------------------------------------------------------------------
    | Public address file
    |--------------------------------------------------------------------------
    |
    | `make public` writes the tunnel's https address here (null = the default
    | storage/app/public_url). Tests point this somewhere else so they never
    | touch the live file.
    |
    */

    'public_url_file' => null,

    /*
    |--------------------------------------------------------------------------
    | Web push
    |--------------------------------------------------------------------------
    |
    | VAPID keys identify this server to the browsers' push services; without
    | them push is off. A turn push older than ttl seconds is dropped rather than
    | delivered late. nudge_cooldown limits how often one player can nudge another.
    |
    */

    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:push@example.com'),
        'ttl' => 300,
        'nudge_cooldown' => 30,
    ],

];
