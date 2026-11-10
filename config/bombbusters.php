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
    | implemented in App\Games\BombBusters\GameEngine::useEquipment().
    |
    */

    'equipment' => [
        // The printed game's twelve cards, numbered as printed (the number is also what unlocks it).
        // Effects live in GameEngine::useEquipment(); the UI input for each is EQUIPMENT_INPUT in game.js.
        'label_different' => ['unlock' => 1],     // mark two neighbouring wires of yours that differ
        'walkie_talkies' => ['unlock' => 2],      // swap one of your wires with one a teammate picks
        'triple_detector' => ['unlock' => 3],     // Double Detector on three wires (a cut action)
        'post_it' => ['unlock' => 4],             // put an extra info token on one of your blue wires
        'super_detector' => ['unlock' => 5],      // Detector on a teammate's whole stand (a cut action)
        'rewinder' => ['unlock' => 6],            // turn the detonator back by one
        'emergency_batteries' => ['unlock' => 7], // recharge one or two used Double Detectors
        'radar' => ['unlock' => 8],               // name a value: everyone shows whether they still hold it
        'stabilizer' => ['unlock' => 9],          // your next wrong cut this turn doesn't tick the detonator
        'xy_ray' => ['unlock' => 10],             // Dual Cut naming two values you hold (a cut action)
        'coffee_mug' => ['unlock' => 11],         // skip your turn and choose who plays next
        'label_equal' => ['unlock' => 12],        // mark two neighbouring wires of yours that are the same
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
