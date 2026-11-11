<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

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
