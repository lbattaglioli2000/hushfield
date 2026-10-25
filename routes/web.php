<?php

use App\Http\Controllers\Identity\AgeGateController;
use App\Http\Controllers\World\AssetManifestController;
use App\Http\Controllers\World\ChatController;
use App\Http\Controllers\World\EndWorldSessionController;
use App\Http\Controllers\World\HeartbeatController;
use App\Http\Controllers\World\JoinRoomController;
use App\Http\Controllers\World\MapController;
use App\Http\Controllers\World\MoveController;
use App\Http\Controllers\World\PlayController;
use App\Http\Controllers\World\ReportController;
use App\Http\Controllers\World\SnapshotController;
use App\Http\Controllers\World\StartWorldSessionController;
use App\Http\Middleware\EnsureWorldSession;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::post('register/age', AgeGateController::class)->middleware(['guest', 'throttle:20,1'])->name('register.age');

Route::get('assets/manifest.json', AssetManifestController::class)->name('assets.manifest');
Route::get('world/maps/{room}/{version}.json', MapController::class)->name('world.maps.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/play')->name('dashboard');
    Route::get('play', PlayController::class)->name('play');

    // Gameplay is plain JSON from here on; Inertia's router is never used for it.
    Route::prefix('world')->name('world.')->group(function () {
        Route::post('session', StartWorldSessionController::class)->name('session.start');

        Route::middleware(EnsureWorldSession::class)->group(function () {
            Route::post('session/end', EndWorldSessionController::class)->name('session.end');
            Route::post('heartbeat', HeartbeatController::class)->name('heartbeat');
            Route::post('rooms/{room}/join', JoinRoomController::class)->middleware('throttle:join-room')->name('rooms.join');
            Route::get('instances/{instance}/snapshot', SnapshotController::class)->name('instances.snapshot');
            Route::post('instances/{instance}/move', MoveController::class)->middleware('throttle:move')->name('instances.move');
            Route::post('instances/{instance}/chat', ChatController::class)->middleware('throttle:chat')->name('instances.chat');
            Route::post('reports', ReportController::class)->middleware('throttle:report')->name('reports.store');
        });
    });
});

require __DIR__.'/settings.php';
