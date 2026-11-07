<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\RoomController;
use Illuminate\Support\Facades\Route;

// Note: /app/* and /apps/* are routed to Reverb by Traefik, so no web routes may live there

Route::get('/', HomeController::class)->name('home');
Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');
Route::post('/player', [PlayerController::class, 'store'])->middleware('throttle:guests')->name('player.store');

Route::middleware('auth')->group(function () {
    Route::middleware('throttle:rooms')->group(function () {
        Route::post('/rooms', [RoomController::class, 'store'])->name('rooms.store');
        Route::post('/rooms/find', [RoomController::class, 'find'])->name('rooms.find');
        Route::post('/rooms/{room}/join', [RoomController::class, 'join'])->name('rooms.join');
    });

    Route::post('/push-subscription', [PushSubscriptionController::class, 'store'])->middleware('throttle:rooms')->name('push.store');

    Route::prefix('/rooms/{room}')->middleware('throttle:play')->name('rooms.')->group(function () {
        Route::get('/', [RoomController::class, 'show'])->name('show');
        Route::get('/game', [GameController::class, 'show'])->name('game');
        Route::post('/game/info-token', [GameController::class, 'infoToken'])->name('game.info-token');
        Route::post('/game/dual-cut', [GameController::class, 'dualCut'])->name('game.dual-cut');
        Route::post('/game/solo-cut', [GameController::class, 'soloCut'])->name('game.solo-cut');
        Route::post('/game/pass', [GameController::class, 'pass'])->name('game.pass');
        Route::post('/game/double-detector', [GameController::class, 'doubleDetector'])->name('game.double-detector');
        Route::post('/game/detector-reveal', [GameController::class, 'revealForDetector'])->name('game.detector-reveal');
        Route::post('/game/reveal-red', [GameController::class, 'revealRed'])->name('game.reveal-red');
        Route::post('/game/walkie', [GameController::class, 'answerWalkie'])->name('game.walkie');
        Route::post('/game/equipment/{equipment}/cancel', [GameController::class, 'cancelEquipment'])->name('game.equipment.cancel');
        Route::post('/game/equipment/{equipment}', [GameController::class, 'useEquipment'])->name('game.equipment');
        Route::post('/game/nudge/{user}', [GameController::class, 'nudge'])->name('game.nudge');
        Route::post('/game/undo', [GameController::class, 'undo'])->name('game.undo');
        Route::post('/game/lobby', [GameController::class, 'returnToLobby'])->name('game.lobby');
        Route::post('/game/next-mission', [GameController::class, 'nextMission'])->name('game.next-mission');
        Route::post('/seat', [RoomController::class, 'seat'])->name('seat');
        Route::post('/ready', [RoomController::class, 'ready'])->name('ready');
        Route::post('/capacity', [RoomController::class, 'capacity'])->name('capacity');
        Route::post('/mission', [RoomController::class, 'mission'])->name('mission');
        Route::post('/kick/{user}', [RoomController::class, 'kick'])->name('kick');
        Route::post('/host/{user}', [RoomController::class, 'transferHost'])->name('host');
        Route::post('/leave', [RoomController::class, 'leave'])->name('leave');
        Route::post('/start', [RoomController::class, 'start'])->name('start');
    });
});
