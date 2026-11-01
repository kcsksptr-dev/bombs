<?php

namespace App\Console\Commands;

use App\Models\Room;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Housekeeping, scheduled daily (routes/console.php): rooms nobody has touched for a day, and guest
 * players who've been gone a month without anything worth keeping (no rooms, no campaign progress).
 */
class PruneStaleData extends Command
{
    protected $signature = 'bombbusters:prune {--room-hours=24} {--guest-days=30}';

    protected $description = 'Delete abandoned rooms and long-gone guest players';

    public function handle(): int
    {
        $roomCutoff = now()->subHours((int) $this->option('room-hours'));
        $guestCutoff = now()->subDays((int) $this->option('guest-days'));

        // A running game keeps its room alive as long as moves are being made
        $rooms = Room::query()
            ->where('updated_at', '<', $roomCutoff)
            ->where(fn (Builder $query) => $query
                ->whereDoesntHave('game')
                ->orWhereHas('game', fn (Builder $game) => $game->where('updated_at', '<', $roomCutoff)))
            ->delete();

        $guests = User::query()
            ->where('updated_at', '<', $guestCutoff)
            ->whereDoesntHave('roomPlayers')
            ->whereDoesntHave('missionCompletions')
            ->whereNotIn('id', Room::query()->select('host_user_id'))
            ->delete();

        $this->info("Deleted {$rooms} rooms and {$guests} guest players.");

        return self::SUCCESS;
    }
}
