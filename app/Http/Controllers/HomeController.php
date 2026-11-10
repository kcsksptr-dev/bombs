<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $activeRoom = $request->user()?->activeRoom();

        return Inertia::render('Home', [
            'activeRoom' => $activeRoom ? [
                'code' => $activeRoom->code,
                'status' => $activeRoom->status,
            ] : null,
            'capacity' => [
                'min' => config('games.min_players'),
                'max' => config('games.max_players'),
                'default' => config('games.default_capacity'),
            ],
        ]);
    }
}
