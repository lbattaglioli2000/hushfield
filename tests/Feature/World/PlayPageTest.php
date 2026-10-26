<?php

use App\Domain\World\RoomKind;
use App\Http\Controllers\World\PlayController;
use App\Models\Room;
use App\Models\User;
use App\Models\WorldSession;
use Illuminate\Support\Facades\File;
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

test('the dial lists every active room with a frequency, lowest first, plus the one station homes share', function () {
    Room::factory()->create(['slug' => 'lantern-street', 'frequency' => 99.1]);
    Room::factory()->create(['slug' => 'town-square', 'frequency' => 94.2]);
    Room::factory()->create(['slug' => 'hearthmore-residences', 'kind' => RoomKind::Residence, 'frequency' => 104.8, 'home_frequency' => 88.3]);
    Room::factory()->create(['slug' => 'unnamed-alley', 'name' => 'Unnamed Alley', 'frequency' => 101.5]);
    Room::factory()->create(['slug' => 'closed-for-now', 'frequency' => 96.0, 'is_active' => false]);
    Room::factory()->create(['slug' => 'off-station']);

    $this->actingAs(User::factory()->create())
        ->get(route('play'))
        ->assertInertia(fn (Assert $page) => $page->where('game.stations', [
            ['frequency' => 88.3, 'label' => __('hushfield.stations.home')],
            ['frequency' => 94.2, 'label' => __('hushfield.stations.town-square')],
            ['frequency' => 99.1, 'label' => __('hushfield.stations.lantern-street')],
            // A room without a label in the lang file is shown by its name.
            ['frequency' => 101.5, 'label' => 'Unnamed Alley'],
            ['frequency' => 104.8, 'label' => __('hushfield.stations.hearthmore-residences')],
        ]));
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
        ->assertJsonPath('images.resident', 'https://cdn.example.com/hf/v7/resident.png')
        ->assertJsonPath('buildings', [])
        ->assertJsonPath('furniture', []);
});

test('the asset manifest lists the art dropped in for each building and piece of furniture', function () {
    $version = 'test-'.uniqid();
    $dir = public_path("assets/v{$version}/buildings");
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/library.png", '');
    File::put("{$dir}/notes.txt", '');
    File::ensureDirectoryExists(public_path("assets/v{$version}/furniture"));
    File::put(public_path("assets/v{$version}/furniture/small-table.png"), '');
    File::put(public_path("assets/v{$version}/furniture/standard-bed-1.png"), '');
    config(['hushfield.assets.base_url' => 'https://cdn.example.com/hf', 'hushfield.assets.version' => $version]);

    try {
        $this->getJson(route('assets.manifest'))
            ->assertOk()
            ->assertExactJson([
                'version' => $version,
                'images' => collect(config('hushfield.assets.images'))->map(fn (string $file) => "https://cdn.example.com/hf/v{$version}/{$file}")->all(),
                'buildings' => ['library' => "https://cdn.example.com/hf/v{$version}/buildings/library.png"],
                'furniture' => [
                    'small-table' => "https://cdn.example.com/hf/v{$version}/furniture/small-table.png",
                    'standard-bed-1' => "https://cdn.example.com/hf/v{$version}/furniture/standard-bed-1.png",
                ],
            ]);
    } finally {
        File::deleteDirectory(public_path("assets/v{$version}"));
    }
});
