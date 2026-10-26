<?php

use App\Domain\Assets\AssetPublisher;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    config(['hushfield.assets.base_url' => 'https://bucket.example.com/hf/']);
});

function bucketIndex(): array
{
    return json_decode((string) file_get_contents(config('hushfield.assets.index')), true);
}

test('the manifest points at the app until the running build is published, then at the bucket', function () {
    $this->getJson(route('assets.manifest'))
        ->assertJsonPath('images.tiles', asset('assets').'/'.bucketIndex()['files']['tiles']);

    $this->artisan('hushfield:publish-assets')->assertSuccessful();

    $this->getJson(route('assets.manifest'))
        ->assertJsonPath('images.tiles', 'https://bucket.example.com/hf/assets/'.bucketIndex()['files']['tiles']);
});

test('publishing uploads only what the bucket lacks, and never deletes', function () {
    $index = bucketIndex();
    $disk = Storage::disk('public');
    $disk->put('assets/tiles.0ld0ld00.png', 'an older build');
    $disk->put("assets/{$index['files']['ui/marker']}", 'already there');

    $this->artisan('hushfield:publish-assets')
        ->expectsOutputToContain(count($index['files']) - 1 .' uploaded, 1 already there')
        ->assertSuccessful();

    foreach ($index['files'] as $file) {
        $disk->assertExists("assets/{$file}");
    }

    $disk->assertExists('assets/tiles.0ld0ld00.png');
    $disk->assertExists("assets/index/{$index['version']}.json");
    // An existing file is not rewritten: its key names its content.
    expect($disk->get("assets/{$index['files']['ui/marker']}"))->toBe('already there');
});

test('prune deletes old files no recent build names, and keeps the rest', function () {
    $disk = Storage::disk('public');
    app(AssetPublisher::class)->publish();
    $old = now()->subDays(31)->getTimestamp();
    $age = fn (string $key) => touch($disk->path($key), $old);

    // Named by a build published last week: kept, however old the file.
    $disk->put('assets/index/recent.json', json_encode(['files' => ['tiles' => 'tiles.11111111.png']]));
    $disk->put('assets/tiles.11111111.png', 'x');
    $age('assets/tiles.11111111.png');
    // Named only by a build from long ago: both go.
    $disk->put('assets/index/ancient.json', json_encode(['files' => ['tiles' => 'tiles.22222222.png']]));
    $age('assets/index/ancient.json');
    $disk->put('assets/tiles.22222222.png', 'x');
    $age('assets/tiles.22222222.png');
    // Named by nothing, but uploaded yesterday: an old tab may still want it.
    $disk->put('assets/tiles.33333333.png', 'x');
    // The running build's files stay even when old.
    $tiles = 'assets/'.bucketIndex()['files']['tiles'];
    $age($tiles);

    $this->artisan('hushfield:prune-assets')->expectsOutputToContain('Pruned 2 files.')->assertSuccessful();

    $disk->assertMissing(['assets/index/ancient.json', 'assets/tiles.22222222.png']);
    $disk->assertExists(['assets/tiles.11111111.png', 'assets/tiles.33333333.png', $tiles]);
});
