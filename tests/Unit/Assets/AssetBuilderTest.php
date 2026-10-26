<?php

use App\Domain\Assets\Image;
use App\Domain\Assets\Png;
use Illuminate\Support\Facades\File;

/** A scratch copy of resources/art and of one real map, with the build pointed at it. */
function scratchArt(): string
{
    $root = sys_get_temp_dir().'/hushfield-art-'.uniqid();
    File::copyDirectory(resource_path('art'), "{$root}/art");
    File::ensureDirectoryExists("{$root}/maps");
    File::copy(resource_path('maps/library.json'), "{$root}/maps/library.json");
    config([
        'hushfield.assets.sources' => "{$root}/art",
        'hushfield.maps.path' => "{$root}/maps",
        'hushfield.assets.output' => "{$root}/built",
        'hushfield.assets.index' => "{$root}/assets.json",
    ]);

    return $root;
}

/** @return array{version: string, files: array<string, string>} */
function builtIndex(): array
{
    return json_decode((string) file_get_contents(config('hushfield.assets.index')), true);
}

function builtImage(string $name): Image
{
    return Png::decode((string) file_get_contents(config('hushfield.assets.output').'/'.builtIndex()['files'][$name]));
}

afterEach(function () {
    File::deleteDirectory(dirname((string) config('hushfield.assets.sources')));
});

it('packs the tile sheet in slot order, her tiles doubled and the rest as placeholders', function () {
    scratchArt();
    $this->artisan('hushfield:build-assets')->assertSuccessful();

    $sheet = builtImage('tiles');
    // Read with GD, not the build's own decoder: every game pixel is its art pixel, 2 × 2.
    $grass = imagecreatefrompng(resource_path('art/tiles/grass/full.png'));
    $mismatched = 0;

    for ($y = 0; $y < 64; $y++) {
        for ($x = 0; $x < 64; $x++) {
            $want = imagecolorsforindex($grass, imagecolorat($grass, intdiv($x, 2), intdiv($y, 2)));
            [, $r, $g, $b, $a] = unpack('C4', substr($sheet->pixels, ($y * $sheet->width + $x) * 4, 4));
            // GD keeps 7 bits of alpha, so allow its rounding there.
            $mismatched += (int) ([$r, $g, $b] !== [$want['red'], $want['green'], $want['blue']] || abs((127 - $want['alpha']) * 2 - $a) > 1);
        }
    }

    $shape = function (int $left) use ($sheet): string {
        $mask = '';

        for ($y = 0; $y < 64; $y++) {
            for ($x = $left; $x < $left + 64; $x++) {
                $mask .= $sheet->alphaAt($x, $y) > 0 ? '#' : '.';
            }
        }

        return $mask;
    };

    expect([$sheet->width, $sheet->height])->toBe([1024, 64])
        ->and($mismatched)->toBe(0)
        // Stone is slot 2, after sand.
        ->and($sheet->crop(128, 0, 64, 64)->equals(Png::decode((string) file_get_contents(resource_path('art/tiles/stone/full.png')))->scale(2)))->toBeTrue()
        // Sand has no art: a block the shape of her stone.
        ->and($shape(64))->toBe($shape(128))
        // Past the last slot, nothing.
        ->and($sheet->crop(6 * 64, 0, 64, 64)->equals(Image::blank(64, 64)))->toBeTrue();
});

/**
 * A plain slab in art pixels, one colour: a 32 × 16 top face from row `top`
 * and sides down to the bottom edge, as resources/maps/README.md describes.
 */
function slab(int $top, int $shift = 0): string
{
    $pixels = '';

    for ($y = 0; $y < 32; $y++) {
        $row = $y + $shift;
        $half = $row < $top ? 0 : ($row <= $top + 7 ? 2 * ($row - $top + 1) : ($row <= 24 ? 16 : max(0, 16 - 2 * ($row - 24))));

        for ($x = 0; $x < 32; $x++) {
            $pixels .= abs($x - 15.5) + 0.5 <= $half ? "\x80\x80\x80\xFF" : "\0\0\0\0";
        }
    }

    return Png::encode(new Image(32, 32, $pixels));
}

it('packs a half slab into a new slot, and checks each height has its own shape', function () {
    $root = scratchArt();
    File::put("{$root}/art/tiles/tiles.json", '["grass/full", "sand/full", "stone/full", "water/full", "hedge/full", "wood/full", "stone/half", "sand/half"]');
    File::put("{$root}/art/tiles/stone/half.png", slab(11));
    File::put("{$root}/art/provenance.json", '{"art/tiles/*/*.png": {"by": "our artist"}, "art/ui/*.png": {"by": "us"}}');
    $this->artisan('hushfield:build-assets')->assertSuccessful();

    $sheet = builtImage('tiles');
    $topRow = function (int $slot) use ($sheet): int {
        for ($y = 0; $y < 64; $y++) {
            if ($sheet->alphaAt($slot * 64 + 32, $y) > 0) {
                return $y;
            }
        }

        return -1;
    };

    // Her half slab doubled into slot 6, and sand's placeholder half slab beside it:
    // each top face 8 game pixels below its full block's, the bottoms level.
    expect($sheet->crop(6 * 64, 0, 64, 64)->equals(Png::decode(slab(11))->scale(2)))->toBeTrue()
        ->and($topRow(7) - $topRow(1))->toBe(8)
        ->and($sheet->alphaAt(7 * 64 + 32, 63))->toBe(255)
        // Nothing else moved.
        ->and($sheet->crop(0, 0, 6 * 64, 64)->equals(Png::decode(file_get_contents(config('hushfield.maps.path').'/ground.png'))->crop(0, 0, 6 * 64, 64)))->toBeTrue();

    $wrong = [
        'stone/half.png' => [slab(7), 'too tall for a half slab'],
        'stone/full.png' => [slab(11), 'too short for a full block'],
        // Drawn 4 pixels too high on the canvas.
        'grass/full.png' => [slab(7, 4), "sides don't reach the bottom of the canvas"],
    ];

    foreach ($wrong as $file => [$png, $message]) {
        $good = File::get("{$root}/art/tiles/{$file}");
        File::put("{$root}/art/tiles/{$file}", $png);
        $this->artisan('hushfield:build-assets')->expectsOutputToContain($message)->assertFailed();
        File::put("{$root}/art/tiles/{$file}", $good);
    }
});

it('leaves a retired slot empty without moving the slots after it', function () {
    $root = scratchArt();
    File::put("{$root}/art/tiles/tiles.json", '["grass/full", null, "stone/full"]');
    $this->artisan('hushfield:build-assets')->assertSuccessful();

    $sheet = builtImage('tiles');

    expect($sheet->crop(64, 0, 64, 64)->equals(Image::blank(64, 64)))->toBeTrue()
        ->and($sheet->crop(128, 0, 64, 64)->equals(Png::decode((string) file_get_contents("{$root}/art/tiles/stone/full.png"))->scale(2)))->toBeTrue();
});

it('names every output by its content hash, so only what changed gets a new name', function () {
    $root = scratchArt();
    $this->artisan('hushfield:build-assets')->assertSuccessful();
    $before = builtIndex();

    foreach ($before['files'] as $file) {
        $bytes = (string) file_get_contents(config('hushfield.assets.output')."/{$file}");
        expect($file)->toEndWith('.'.substr(hash('sha256', $bytes), 0, 8).'.png');
    }

    // Brighten one stone pixel.
    $stone = Png::decode((string) file_get_contents("{$root}/art/tiles/stone/full.png"));
    File::put("{$root}/art/tiles/stone/full.png", Png::encode(new Image(32, 32, substr_replace($stone->pixels, "\xFF\xFF\xFF\xFF", (16 * 32 + 16) * 4, 4))));
    $this->artisan('hushfield:build-assets')->assertSuccessful();
    $after = builtIndex();

    expect($after['files']['tiles'])->not->toBe($before['files']['tiles'])
        ->and(array_diff_key($after['files'], ['tiles' => 1]))->toBe(array_diff_key($before['files'], ['tiles' => 1]))
        ->and($after['version'])->not->toBe($before['version'])
        // The old sheet is gone; the unchanged files stay.
        ->and(File::allFiles(config('hushfield.assets.output')))->toHaveCount(count($after['files']));
});

it('writes the tileset block into every map and changes nothing else', function () {
    $root = scratchArt();
    $original = json_decode((string) file_get_contents("{$root}/maps/library.json"), true);
    $this->artisan('hushfield:build-assets')->assertSuccessful();

    $map = json_decode((string) file_get_contents("{$root}/maps/library.json"), true);

    expect($map['tilesets'][0])->toMatchArray([
        'firstgid' => 1,
        'name' => 'placeholder',
        'image' => 'ground.png',
        'imagewidth' => 1024,
        'imageheight' => 64,
        'tilewidth' => 64,
        'tileheight' => 64,
        'columns' => 16,
        'tilecount' => 6,
        'tileoffset' => ['x' => 0, 'y' => 16],
    ])
        ->and($map['tilesets'][0]['tiles'][2])->toBe(['id' => 2, 'properties' => [['name' => 'slug', 'type' => 'string', 'value' => 'stone/full']]])
        ->and(array_diff_key($map, ['tilesets' => 1]))->toBe(array_diff_key($original, ['tilesets' => 1]))
        ->and(file_get_contents("{$root}/maps/ground.png"))->toBe(file_get_contents(config('hushfield.assets.output').'/'.builtIndex()['files']['tiles']));
});

it('refuses art it cannot ship, and says why', function (Closure $break, string $message) {
    $root = scratchArt();
    $break($root);

    $this->artisan('hushfield:build-assets')
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'the wrong size' => [
        fn (string $root) => File::put("{$root}/art/tiles/stone/full.png", Png::encode(Image::blank(32, 30))),
        'art/tiles/stone/full.png is 32×30; it must be 32×32.',
    ],
    'exported at 200%' => [
        fn (string $root) => File::put("{$root}/art/tiles/stone/full.png", Png::encode(Png::decode((string) file_get_contents("{$root}/art/tiles/stone/full.png"))->scale(2))),
        'art/tiles/stone/full.png is already scaled up to 64×64. Export it at 100%: 32×32.',
    ],
    'a tile with no slot' => [
        function (string $root) {
            File::copy("{$root}/art/tiles/stone/full.png", "{$root}/art/tiles/stone/half.png");
            File::put("{$root}/art/provenance.json", '{"art/tiles/*/*.png": {"by": "our artist"}, "art/ui/*.png": {"by": "us"}}');
        },
        'art/tiles/stone/half.png has no slot. Add "stone/half" to the end of tiles/tiles.json.',
    ],
    'a file nobody claimed' => [
        function (string $root) {
            File::ensureDirectoryExists("{$root}/art/tiles/sand");
            File::copy("{$root}/art/tiles/stone/full.png", "{$root}/art/tiles/sand/full.png");
        },
        'No entry in art/provenance.json for: art/tiles/sand/full.png.',
    ],
    'a name that is not a slug' => [
        fn (string $root) => File::copy("{$root}/art/tiles/stone/full.png", "{$root}/art/tiles/stone/Full Final.png"),
        "art/tiles/stone/Full Final.png isn't a file the build knows.",
    ],
    'a tile outside its type folder' => [
        fn (string $root) => File::copy("{$root}/art/tiles/stone/full.png", "{$root}/art/tiles/stone.png"),
        "art/tiles/stone.png isn't a file the build knows.",
    ],
]);
