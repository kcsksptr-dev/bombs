<?php

namespace App\Http\Controllers;

use App\Games\BombBusters\GameEngine;
use App\Models\Room;
use App\Services\GameService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Bomb Busters moves. Each one runs the pure GameEngine through GameService::move().
 */
class BombBustersController extends Controller
{
    /**
     * What a player may name when cutting: a blue number or "yellow".
     */
    private const KIND_RULE = 'regex:/^([1-9]|1[0-2]|yellow)$/';

    public function __construct(
        private readonly GameService $games,
        private readonly GameEngine $engine,
    ) {}

    public function infoToken(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate(['index' => ['required', 'integer', 'min:0']]);
        $this->move($room, fn (array $state) => $this->engine->placeInfoToken($state, $request->user()->id, (int) $validated['index']));

        return back();
    }

    public function dualCut(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'integer'],
            'index' => ['required', 'integer', 'min:0'],
            'value' => ['required', self::KIND_RULE],
        ]);

        $this->move($room, fn (array $state) => $this->engine->dualCut($state, $request->user()->id, (int) $validated['target'], (int) $validated['index'], $validated['value']));

        return back();
    }

    public function soloCut(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate(['value' => ['required', self::KIND_RULE]]);
        $this->move($room, fn (array $state) => $this->engine->soloCut($state, $request->user()->id, $validated['value']));

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

        $this->move($room, fn (array $state) => $this->engine->doubleDetector($state, $request->user()->id, (int) $validated['target'], $validated['indices'], $validated['value']));

        return back();
    }

    public function revealForDetector(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate(['index' => ['required', 'integer', 'min:0']]);
        $this->move($room, fn (array $state) => $this->engine->revealForDetector($state, $request->user()->id, (int) $validated['index']));

        return back();
    }

    public function pass(Request $request, Room $room): RedirectResponse
    {
        $this->move($room, fn (array $state) => $this->engine->pass($state, $request->user()->id));

        return back();
    }

    public function revealRed(Request $request, Room $room): RedirectResponse
    {
        $this->move($room, fn (array $state) => $this->engine->revealRed($state, $request->user()->id));

        return back();
    }

    public function useEquipment(Request $request, Room $room, string $equipment): RedirectResponse
    {
        $validated = $request->validate([
            'index' => ['sometimes', 'integer', 'min:0'],
            // A number, or 'yellow' for the detectors and the X or Y Ray
            'value' => ['sometimes', 'regex:/^([1-9]|1[0-2]|yellow)$/'],
            'target' => ['sometimes', 'integer'],
            'player' => ['sometimes', 'integer'],
            'indices' => ['sometimes', 'array', 'max:12'],
            'indices.*' => ['integer', 'min:0'],
            'values' => ['sometimes', 'array', 'size:2'],
            'values.*' => ['regex:/^([1-9]|1[0-2]|yellow)$/'],
            'players' => ['sometimes', 'array', 'max:2'],
            'players.*' => ['integer'],
        ]);

        $this->move($room, fn (array $state) => $this->engine->useEquipment($state, $request->user()->id, $equipment, $validated));

        return back();
    }

    public function answerWalkie(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate(['index' => ['required', 'integer', 'min:0']]);
        $this->move($room, fn (array $state) => $this->engine->answerWalkie($state, $request->user()->id, (int) $validated['index']));

        return back();
    }

    public function cancelEquipment(Request $request, Room $room, string $equipment): RedirectResponse
    {
        $this->move($room, fn (array $state) => $this->engine->cancelEquipment($state, $request->user()->id, $equipment));

        return back();
    }

    /**
     * After a win: back to the lobby with the next mission selected.
     */
    public function nextMission(Request $request, Room $room): RedirectResponse
    {
        $this->games->returnToLobby($room, $request->user(), advance: true);

        return to_route('rooms.show', $room);
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $move
     */
    private function move(Room $room, Closure $move): void
    {
        $this->games->move($room, 'bombbusters', $move);
    }
}
