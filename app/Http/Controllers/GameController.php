<?php

namespace App\Http\Controllers;

use App\Enums\RoomStatus;
use App\Games\GameRegistry;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use App\Services\GameService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The parts of a running game every game shares: the page, nudges, undo and going back to the lobby.
 * Moves live in each game's own controller.
 */
class GameController extends Controller
{
    public function __construct(
        private readonly GameService $games,
    ) {}

    public function show(Request $request, Room $room, GameRegistry $games): Response|RedirectResponse
    {
        $room->load('players.user', 'game');
        $user = $request->user();

        if (! $room->playerFor($user) || $room->status !== RoomStatus::Playing || ! $room->game) {
            return to_route('rooms.show', $room);
        }

        $players = $room->players->mapWithKeys(fn (RoomPlayer $player) => [
            $player->user_id => ['name' => $player->user->name, 'seat' => $player->seat],
        ])->all();

        $definition = $games->for($room);

        return Inertia::render($definition->page(), [
            'room' => RoomController::roomProps($room),
            'game' => $definition->view($room->game->state, $user->id, $players, $room->game->snapshots()->count()),
            'me' => ['id' => $user->id, 'isHost' => $room->isHost($user)],
        ]);
    }

    public function nudge(Request $request, Room $room, User $user): RedirectResponse
    {
        $this->games->nudge($room, $request->user(), $user);

        return back();
    }

    public function undo(Request $request, Room $room): RedirectResponse
    {
        $this->games->undo($room, $request->user());

        return back();
    }

    public function returnToLobby(Request $request, Room $room): RedirectResponse
    {
        $this->games->returnToLobby($room, $request->user());

        return to_route('rooms.show', $room);
    }
}
