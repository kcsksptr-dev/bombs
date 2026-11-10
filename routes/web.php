<?php

use App\Http\Controllers\BombBustersController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MoonwellController;
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
        Route::post('/game/info-token', [BombBustersController::class, 'infoToken'])->name('game.info-token');
        Route::post('/game/dual-cut', [BombBustersController::class, 'dualCut'])->name('game.dual-cut');
        Route::post('/game/solo-cut', [BombBustersController::class, 'soloCut'])->name('game.solo-cut');
        Route::post('/game/pass', [BombBustersController::class, 'pass'])->name('game.pass');
        Route::post('/game/double-detector', [BombBustersController::class, 'doubleDetector'])->name('game.double-detector');
        Route::post('/game/detector-reveal', [BombBustersController::class, 'revealForDetector'])->name('game.detector-reveal');
        Route::post('/game/reveal-red', [BombBustersController::class, 'revealRed'])->name('game.reveal-red');
        Route::post('/game/walkie', [BombBustersController::class, 'answerWalkie'])->name('game.walkie');
        Route::post('/game/equipment/{equipment}/cancel', [BombBustersController::class, 'cancelEquipment'])->name('game.equipment.cancel');
        Route::post('/game/equipment/{equipment}', [BombBustersController::class, 'useEquipment'])->name('game.equipment');
        Route::post('/game/take', [MoonwellController::class, 'take'])->name('game.take');
        Route::post('/game/nudge/{user}', [GameController::class, 'nudge'])->name('game.nudge');
        Route::post('/game/undo', [GameController::class, 'undo'])->name('game.undo');
        Route::post('/game/lobby', [GameController::class, 'returnToLobby'])->name('game.lobby');
        Route::post('/game/next-mission', [BombBustersController::class, 'nextMission'])->name('game.next-mission');
        Route::post('/seat', [RoomController::class, 'seat'])->name('seat');
        Route::post('/ready', [RoomController::class, 'ready'])->name('ready');
        Route::post('/capacity', [RoomController::class, 'capacity'])->name('capacity');
        Route::post('/choose-game', [RoomController::class, 'chooseGame'])->name('choose-game');
        Route::post('/mission', [RoomController::class, 'mission'])->name('mission');
        Route::post('/kick/{user}', [RoomController::class, 'kick'])->name('kick');
        Route::post('/host/{user}', [RoomController::class, 'transferHost'])->name('host');
        Route::post('/leave', [RoomController::class, 'leave'])->name('leave');
        Route::post('/start', [RoomController::class, 'start'])->name('start');
    });
});
