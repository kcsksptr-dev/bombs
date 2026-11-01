<?php

namespace App\Http\Controllers;

use App\Enums\RoomStatus;
use App\Game\GameView;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use App\Services\GameService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    /**
     * What a player may name when cutting: a blue number or "yellow".
     */
    private const KIND_RULE = 'regex:/^([1-9]|1[0-2]|yellow)$/';

    public function __construct(
        private readonly GameService $games,
    ) {}

    public function show(Request $request, Room $room, GameView $view): Response|RedirectResponse
    {
        $room->load('players.user', 'game');
        $user = $request->user();

        if (! $room->playerFor($user) || $room->status !== RoomStatus::Playing || ! $room->game) {
            return to_route('rooms.show', $room);
        }

        $players = $room->players->mapWithKeys(fn (RoomPlayer $player) => [
            $player->user_id => ['name' => $player->user->name, 'seat' => $player->seat],
        ])->all();

        return Inertia::render('Room/Game', [
            'room' => RoomController::roomProps($room),
            'game' => $view->for($room->game->state, $user->id, $players, $room->game->snapshots()->count()),
            'me' => ['id' => $user->id, 'isHost' => $room->isHost($user)],
        ]);
    }

    public function infoToken(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate(['index' => ['required', 'integer', 'min:0']]);
        $this->games->placeInfoToken($room, $request->user(), (int) $validated['index']);

        return back();
    }

    public function dualCut(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'integer'],
            'index' => ['required', 'integer', 'min:0'],
            'value' => ['required', self::KIND_RULE],
        ]);

        $this->games->dualCut($room, $request->user(), (int) $validated['target'], (int) $validated['index'], $validated['value']);

        return back();
    }

    public function soloCut(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate(['value' => ['required', self::KIND_RULE]]);
        $this->games->soloCut($room, $request->user(), $validated['value']);

        return back();
    }

    public function doubleDetector(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'integer'],
            'indices' => ['required', 'array', 'size:2'],
            'indices.*' => ['integer', 'min:0'],
            'value' => ['required', self::KIND_RULE],
        ]);

        $this->games->doubleDetector($room, $request->user(), (int) $validated['target'], $validated['indices'], $validated['value']);

        return back();
    }

    public function revealForDetector(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate(['index' => ['required', 'integer', 'min:0']]);
        $this->games->revealForDetector($room, $request->user(), (int) $validated['index']);

        return back();
    }

    public function pass(Request $request, Room $room): RedirectResponse
    {
        $this->games->pass($room, $request->user());

        return back();
    }

    public function revealRed(Request $request, Room $room): RedirectResponse
    {
        $this->games->revealRed($room, $request->user());

        return back();
    }

    public function useEquipment(Request $request, Room $room, string $equipment): RedirectResponse
    {
        $validated = $request->validate([
            'index' => ['sometimes', 'integer', 'min:0'],
            'value' => ['sometimes', 'integer', 'min:1'],
        ]);

        $this->games->useEquipment($room, $request->user(), $equipment, $validated);

        return back();
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

    /**
     * After a win: back to the lobby with the next mission selected.
     */
    public function nextMission(Request $request, Room $room): RedirectResponse
    {
        $this->games->returnToLobby($room, $request->user(), nextMission: true);

        return to_route('rooms.show', $room);
    }
}
