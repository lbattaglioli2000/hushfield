<?php

use App\Http\Controllers\Economy\ClaimStipendController;
use App\Http\Controllers\Economy\PurchaseItemController;
use App\Http\Controllers\Economy\ShopController;
use App\Http\Controllers\Economy\WalletController;
use App\Http\Controllers\Fishing\FishingController;
use App\Http\Controllers\Housing\HomesController;
use App\Http\Controllers\Housing\HomeVisitorsController;
use App\Http\Controllers\Housing\PlaceFurnitureController;
use App\Http\Controllers\Housing\PutAwayFurnitureController;
use App\Http\Controllers\Identity\AgeGateController;
use App\Http\Controllers\Social\FriendsController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\World\AssetFileController;
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
use App\Http\Controllers\World\TownMapController;
use App\Http\Controllers\World\UseExitController;
use App\Http\Controllers\World\VoiceTokenController;
use App\Http\Middleware\EnsureWorldSession;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');

Route::post('register/age', AgeGateController::class)->middleware(['guest', 'throttle:20,1'])->name('register.age');

Route::get('assets/manifest.json', AssetManifestController::class)->name('assets.manifest');
Route::get('assets/{path}', AssetFileController::class)->where('path', '[a-z0-9/-]+\.[0-9a-f]{8}\.png')->name('assets.file');
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
            Route::post('instances/{instance}/exits/{exit}', UseExitController::class)->middleware('throttle:join-room')->name('instances.exit');
            Route::post('instances/{instance}/chat', ChatController::class)->middleware('throttle:chat')->name('instances.chat');
            Route::post('instances/{instance}/voice', VoiceTokenController::class)->middleware('throttle:voice')->name('instances.voice');
            Route::get('town', TownMapController::class)->name('town');
            Route::post('reports', ReportController::class)->middleware('throttle:report')->name('reports.store');

            Route::get('wallet', WalletController::class)->name('wallet');
            Route::post('stipend', ClaimStipendController::class)->middleware('throttle:economy')->name('stipend.claim');
            Route::get('shop', ShopController::class)->name('shop');
            Route::post('shop/{item}/purchase', PurchaseItemController::class)->middleware('throttle:economy')->name('shop.purchase');

            Route::middleware('throttle:fishing')->group(function () {
                Route::post('fishing/cast', [FishingController::class, 'cast'])->name('fishing.cast');
                Route::post('fishing/reel', [FishingController::class, 'reel'])->name('fishing.reel');
            });

            Route::get('friends', [FriendsController::class, 'index'])->name('friends');
            Route::middleware('throttle:friends')->group(function () {
                Route::post('friends/requests', [FriendsController::class, 'send'])->name('friends.requests.send');
                Route::post('friends/requests/{friendRequest}/accept', [FriendsController::class, 'accept'])->name('friends.requests.accept');
                Route::delete('friends/requests/{friendRequest}', [FriendsController::class, 'dismiss'])->name('friends.requests.dismiss');
                Route::delete('friends/{friend}', [FriendsController::class, 'remove'])->name('friends.remove');
                Route::post('friends/settings', [FriendsController::class, 'settings'])->name('friends.settings');
            });

            Route::get('homes', HomesController::class)->name('homes');
            Route::middleware('throttle:home')->group(function () {
                Route::post('home/visitors', HomeVisitorsController::class)->name('home.visitors');
                Route::post('home/furniture/{piece}', PlaceFurnitureController::class)->name('home.furniture.place');
                Route::delete('home/furniture/{piece}', PutAwayFurnitureController::class)->name('home.furniture.remove');
            });
        });
    });
});

require __DIR__.'/settings.php';
