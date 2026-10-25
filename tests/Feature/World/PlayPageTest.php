<?php

use App\Http\Controllers\World\PlayController;
use App\Models\User;
use App\Models\WorldSession;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are sent to the login page', function () {
    $this->get(route('play'))->assertRedirect(route('login'));
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->postJson(route('world.session.start'))->assertUnauthorized();
});

test('the dashboard redirects to the game', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect('/play');
});

test('verified residents get the game shell', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('play'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Play')
            ->where('auth.user.username', $user->username)
            ->where('game.protocol', 1)
            ->where('game.chatMaxLength', 120)
            ->where('game.manifestUrl', route('assets.manifest'))
            ->has('game.reportReasons', 7)
            // Only the lines the client shows (resources/js/game/copy.ts), not the whole lang file.
            ->where('game.copy', fn ($copy): bool => collect($copy)->keys()->sort()->values()->all() === collect(PlayController::COPY_KEYS)->sort()->values()->all())
            ->where('game.copy.reconnecting', __('hushfield.reconnecting'))
            ->where('game.copy.session_moved_action', __('hushfield.session_moved_action'))
            ->missing('game.copy.filtered_placeholder')
            ->missing('game.copy.username_taken'),
        );
});

test('the game shell does not expose the birth year', function () {
    $user = User::factory()->create(['birth_year' => 1991]);

    $this->actingAs($user)
        ->get(route('play'))
        ->assertInertia(fn (Assert $page) => $page->missing('auth.user.birth_year')->missing('auth.user.password'));
});

test('unverified residents cannot reach the game or the world API', function () {
    $room = importRoom();
    $instance = $room->instances()->firstOrFail();
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    $this->get(route('play'))->assertRedirect(route('verification.notice'));
    $this->postJson(route('world.session.start'))->assertForbidden();
    $this->postJson('/world/heartbeat')->assertForbidden();
    $this->postJson(route('world.rooms.join', $room))->assertForbidden();
    $this->getJson(route('world.instances.snapshot', $instance))->assertForbidden();
    $this->postJson(route('world.instances.move', $instance), ['x' => 1, 'y' => 1])->assertForbidden();
    $this->postJson(route('world.instances.chat', $instance), ['body' => 'hi'])->assertForbidden();

    expect(WorldSession::query()->count())->toBe(0);
});

test('the asset manifest lists versioned asset URLs', function () {
    config(['hushfield.assets.base_url' => 'https://cdn.example.com/hf/', 'hushfield.assets.version' => '7']);

    $this->getJson(route('assets.manifest'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-cache, private')
        ->assertJsonPath('version', '7')
        ->assertJsonPath('images.tiles', 'https://cdn.example.com/hf/v7/tiles.png')
        ->assertJsonPath('images.resident', 'https://cdn.example.com/hf/v7/resident.png');
});
