<?php

use App\Domain\Identity\StaffRole;
use App\Filament\Pages\LiveRooms;
use App\Jobs\PackMapsForTiled;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->maps = sys_get_temp_dir().'/pack-maps-'.uniqid();
    File::ensureDirectoryExists("{$this->maps}/props");
    config(['hushfield.maps.path' => $this->maps]);
    Storage::fake('private');
});

afterEach(fn () => File::deleteDirectory($this->maps));

test('packs every map with the pictures Tiled shows beside it, as one folder', function () {
    File::put("{$this->maps}/laundromat.json", '{"width":30}');
    File::put("{$this->maps}/library.json", '{}');
    File::put("{$this->maps}/ground.png", 'ground');
    File::put("{$this->maps}/props/washer-0-on.png", 'washer');
    File::put("{$this->maps}/README.md", 'not for Tiled');

    $this->artisan('hushfield:pack-maps')
        ->expectsOutputToContain('Packed 4 files into tiled/hushfield-maps-')
        ->assertSuccessful();

    $packed = Storage::disk('private')->files('tiled');
    expect($packed)->toHaveCount(1);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('private')->path($packed[0]));
    $entries = array_map(fn (int $i): string => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    sort($entries);

    expect($entries)->toBe([
        'hushfield-maps/ground.png',
        'hushfield-maps/laundromat.json',
        'hushfield-maps/library.json',
        'hushfield-maps/props/washer-0-on.png',
    ])->and($zip->getFromName('hushfield-maps/laundromat.json'))->toBe('{"width":30}');
});

test('refuses to pack maps Tiled could not show', function () {
    File::put("{$this->maps}/laundromat.json", '{}');

    $this->artisan('hushfield:pack-maps')
        ->expectsOutputToContain('Run php artisan hushfield:build-assets first')
        ->assertFailed();

    expect(Storage::disk('private')->allFiles())->toBe([]);
});

test('an admin packs the maps from Live rooms on the map-exports queue', function () {
    Queue::fake();
    $admin = User::factory()->staff(StaffRole::Admin)->create();
    $this->actingAs($admin);

    Livewire::test(LiveRooms::class)
        ->callAction('packMaps')
        ->assertNotified('Packing the maps. The link comes in your notifications.');

    Queue::assertPushedOn('map-exports', PackMapsForTiled::class, fn (PackMapsForTiled $job): bool => $job->staff->is($admin));
});

test('moderators do not see the button', function () {
    $this->actingAs(User::factory()->staff()->create());

    Livewire::test(LiveRooms::class)->assertActionHidden('packMaps');
});

test('the packed maps reach the staff member who asked, with a download link', function () {
    File::put("{$this->maps}/laundromat.json", '{}');
    File::put("{$this->maps}/ground.png", 'ground');
    Storage::disk('private')->buildTemporaryUrlsUsing(fn (string $path): string => "https://bucket.test/{$path}?signed");
    $admin = User::factory()->staff(StaffRole::Admin)->create();

    PackMapsForTiled::dispatchSync($admin);

    $notification = $admin->notifications()->sole()->data;
    expect($notification['title'])->toBe('Maps packed for Tiled')
        ->and($notification['body'])->toContain('2 files')
        ->and($notification['actions'][0]['url'])->toStartWith('https://bucket.test/tiled/hushfield-maps-');
});

test('a pack that fails tells the staff member why', function () {
    $admin = User::factory()->staff(StaffRole::Admin)->create();

    // No ground.png: the build hasn't written the pictures.
    rescue(fn () => PackMapsForTiled::dispatchSync($admin), report: false);

    expect($admin->notifications()->sole()->data['title'])->toBe("The maps couldn't be packed");
});
