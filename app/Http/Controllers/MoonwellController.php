<?php

namespace App\Http\Controllers;

use App\Games\Moonwell\MoonwellEngine;
use App\Models\Room;
use App\Services\GameService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Moonwell Garden moves. There is only one: take a tile from the ring and plant it.
 */
class MoonwellController extends Controller
{
    public function __construct(
        private readonly GameService $games,
        private readonly MoonwellEngine $engine,
    ) {}

    public function take(Request $request, Room $room): RedirectResponse
    {
        $reach = MoonwellEngine::REACH;
        $validated = $request->validate([
            'slot' => ['required', 'integer', 'min:0', 'max:31'],
            'x' => ['required', 'integer', "between:-{$reach},{$reach}"],
            'y' => ['required', 'integer', "between:-{$reach},{$reach}"],
        ]);

        $this->games->move($room, 'moonwell', fn (array $state) => $this->engine->take(
            $state,
            $request->user()->id,
            (int) $validated['slot'],
            (int) $validated['x'],
            (int) $validated['y'],
        ));

        return back();
    }
}
