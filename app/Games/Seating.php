<?php

namespace App\Games;

use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Support\Collection;

/**
 * Who starts: the host starts a room's first game; after that it passes to the next seated player,
 * however the previous game ended (rooms.last_captain_seat).
 */
class Seating
{
    /**
     * @param  Collection<int, RoomPlayer>  $players  sorted by seat
     */
    public static function nextStarter(Room $room, Collection $players): int
    {
        if ($room->last_captain_seat === null) {
            return $room->host_user_id;
        }

        $next = $players->first(fn (RoomPlayer $player) => $player->seat > $room->last_captain_seat) ?? $players->first();

        return $next->user_id;
    }

    public static function seatOf(Room $room, ?int $userId): ?int
    {
        return $userId === null ? null : $room->players()->where('user_id', $userId)->value('seat');
    }
}
