<?php

use App\Domain\Assets\ArtStatus;
use App\Domain\Assets\Image;
use App\Domain\Assets\ItemArt;
use App\Domain\Assets\Png;
use App\Domain\Items\ItemSlot;
use App\Models\Item;
use Illuminate\Support\Facades\File;

/** A scratch copy of resources/art and of one real map, with the build pointed at it. */
function scratchArt(): string
{
    $root = sys_get_temp_dir().'/hushfield-art-'.uniqid();
    File::copyDirectory(resource_path('art'), "{$root}/art");
    File::ensureDirectoryExists("{$root}/maps");
    File::copy(resource_path('maps/library.json'), "{$root}/maps/library.json");
    File::copy(resource_path('maps/lantern-street.json'), "{$root}/maps/lantern-street.json");
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
    $this->artisan('hushfield:build-assets')
        ->expectsOutputToContain('tiles/stone/full has no art yet: packing tiles/stone/half.png in its slot')
        ->assertSuccessful();

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
        // Stone has only a half slab: it stands in for stone/full (slot 2) and fills its own slot (9).
        ->and($sheet->crop(128, 0, 64, 64)->equals(Png::decode((string) file_get_contents(resource_path('art/tiles/stone/half.png')))->scale(2)))->toBeTrue()
        ->and($sheet->crop(9 * 64, 0, 64, 64)->equals($sheet->crop(128, 0, 64, 64)))->toBeTrue()
        // Sand is her art, doubled.
        ->and($sheet->crop(64, 0, 64, 64)->equals(Png::decode((string) file_get_contents(resource_path('art/tiles/sand/full.png')))->scale(2)))->toBeTrue()
        // Hedge has no art: a placeholder cube the shape of her grass, less its tufts.
        ->and(substr($shape(4 * 64), 16 * 64))->toBe(substr($shape(0), 16 * 64))
        // Past the last slot, nothing.
        ->and($sheet->crop(10 * 64, 0, 64, 64)->equals(Image::blank(64, 64)))->toBeTrue();
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
    $slots = json_decode(File::get("{$root}/art/tiles/tiles.json"));
    File::put("{$root}/art/tiles/tiles.json", json_encode([...$slots, 'grass/half', 'hedge/half']));
    File::put("{$root}/art/tiles/grass/half.png", slab(8));
    File::put("{$root}/art/tiles/stone/full.png", slab(0));
    File::put("{$root}/art/provenance.json", '{"art/tiles/*/*.png": {"by": "our artist"}, "art/ui/*.png": {"by": "us"}}');
    $this->artisan('hushfield:build-assets')
        ->doesntExpectOutputToContain('tiles/stone/full has no art yet')
        ->assertSuccessful();
    $count = count($slots);

    $sheet = builtImage('tiles');
    $topRow = function (int $slot) use ($sheet): int {
        for ($y = 0; $y < 64; $y++) {
            if ($sheet->alphaAt($slot * 64 + 32, $y) > 0) {
                return $y;
            }
        }

        return -1;
    };

    // A half slab doubled into its new slot, and hedge's placeholder half slab after it:
    // each top face 16 game pixels below its full cube's, the bottoms level.
    expect($sheet->crop($count * 64, 0, 64, 64)->equals(Png::decode(slab(8))->scale(2)))->toBeTrue()
        ->and($topRow($count + 1) - $topRow(4))->toBe(16)
        ->and($topRow(4))->toBe(0)
        ->and($sheet->alphaAt(($count + 1) * 64 + 32, 63))->toBe(255)
        // Stone's own full cube replaces the half slab that stood in for it.
        ->and($sheet->crop(128, 0, 64, 64)->equals(Png::decode(slab(0))->scale(2)))->toBeTrue();

    $wrong = [
        'stone/half.png' => [slab(0), 'too tall for a half slab'],
        'stone/full.png' => [slab(8), 'too short for a full cube'],
        // A half slab drawn 8 pixels too high on the canvas.
        'grass/half.png' => [slab(8, 8), "sides don't reach the bottom of the canvas"],
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
    $slots = json_decode(File::get("{$root}/art/tiles/tiles.json"));
    $slots[4] = null;
    File::put("{$root}/art/tiles/tiles.json", json_encode($slots));
    $this->artisan('hushfield:build-assets')->assertSuccessful();

    $sheet = builtImage('tiles');

    expect($sheet->crop(4 * 64, 0, 64, 64)->equals(Image::blank(64, 64)))->toBeTrue()
        ->and($sheet->crop(5 * 64, 0, 64, 64)->equals(Png::decode((string) file_get_contents("{$root}/art/tiles/wood/full.png"))->scale(2)))->toBeTrue();
});

it('names every output by its content hash, so only what changed gets a new name', function () {
    $root = scratchArt();
    $this->artisan('hushfield:build-assets')->assertSuccessful();
    $before = builtIndex();

    foreach ($before['files'] as $file) {
        $bytes = (string) file_get_contents(config('hushfield.assets.output')."/{$file}");
        expect($file)->toEndWith('.'.substr(hash('sha256', $bytes), 0, 8).'.png');
    }

    // Brighten one grass pixel.
    $stone = Png::decode((string) file_get_contents("{$root}/art/tiles/grass/full.png"));
    File::put("{$root}/art/tiles/grass/full.png", Png::encode(new Image(32, 32, substr_replace($stone->pixels, "\xFF\xFF\xFF\xFF", (16 * 32 + 16) * 4, 4))));
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
        'tilecount' => 10,
        'tileoffset' => ['x' => 0, 'y' => 32],
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
        fn (string $root) => File::put("{$root}/art/tiles/grass/full.png", Png::encode(Image::blank(32, 30))),
        'art/tiles/grass/full.png is 32×30; it must be 32×32.',
    ],
    'exported at 200%' => [
        fn (string $root) => File::put("{$root}/art/tiles/grass/full.png", Png::encode(Png::decode((string) file_get_contents("{$root}/art/tiles/grass/full.png"))->scale(2))),
        'art/tiles/grass/full.png is already scaled up to 64×64. Export it at 100%: 32×32.',
    ],
    'a tile with no slot' => [
        function (string $root) {
            File::copy("{$root}/art/tiles/stone/half.png", "{$root}/art/tiles/grass/half.png");
            File::put("{$root}/art/provenance.json", '{"art/tiles/*/*.png": {"by": "our artist"}, "art/ui/*.png": {"by": "us"}}');
        },
        'art/tiles/grass/half.png has no slot. Add "grass/half" to the end of tiles/tiles.json.',
    ],
    'a file nobody claimed' => [
        function (string $root) {
            File::ensureDirectoryExists("{$root}/art/tiles/hedge");
            File::copy("{$root}/art/tiles/grass/full.png", "{$root}/art/tiles/hedge/full.png");
        },
        'No entry in art/provenance.json for: art/tiles/hedge/full.png.',
    ],
    'a tile drawn at full size' => [
        fn (string $root) => File::put("{$root}/art/tiles/bricks/full.png", Png::encode(Image::blank(64, 64)->paste(new Image(1, 1, "\xFF\x00\x00\xFF"), 31, 40))),
        'art/tiles/bricks/full.png is 64×64; it must be 32×32.',
    ],
    'a name that is not a slug' => [
        fn (string $root) => File::copy("{$root}/art/tiles/stone/half.png", "{$root}/art/tiles/stone/Full Final.png"),
        "art/tiles/stone/Full Final.png isn't a file the build knows.",
    ],
    'a tile outside its type folder' => [
        fn (string $root) => File::copy("{$root}/art/tiles/stone/half.png", "{$root}/art/tiles/stone.png"),
        "art/tiles/stone.png isn't a file the build knows.",
    ],
]);

/**
 * Art in art pixels standing on a footprint: the diamond whose bottom corner
 * touches the canvas's bottom `corner` pixels in, and a wall `tall` pixels
 * high above it, one colour. `below` paints one stray pixel under the diamond.
 */
function standing(int $width, int $corner, int $tall, bool $below = false): string
{
    $height = intdiv($width, 2) + $tall;
    $pixels = '';

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $edge = $height - abs($x + 0.5 - $corner) / 2;
            $pixels .= $y <= $edge ? "\x60\x40\x20\xFF" : "\0\0\0\0";
        }
    }

    $image = new Image($width, $height, $pixels);

    if ($below) {
        $image = $image->paste(new Image(1, 1, "\xFF\xFF\xFF\xFF"), 0, $height - 1);
    }

    return Png::encode($image);
}

/** A wall panel in art pixels: `width` across, standing on a floor edge climbing `climb` across it. */
function panel(bool $left, int $width = 16, int $height = 56, int $climb = 8, bool $below = false): string
{
    $pixels = '';

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $edge = $height - ($left ? $x + 0.5 : $width - $x - 0.5) * $climb / $width;
            $pixels .= $y <= $edge || ($below && $y === $height - 1 && $x === ($left ? $width - 1 : 0)) ? "\xA0\xA0\xA0\xFF" : "\0\0\0\0";
        }
    }

    return Png::encode(new Image($width, $height, $pixels));
}

function claimAll(string $root): void
{
    File::put("{$root}/art/provenance.json", (string) json_encode([
        'art/tiles/*/*.png' => ['by' => 'our artist'],
        'art/ui/*.png' => ['by' => 'us'],
        'art/buildings/*.png' => ['by' => 'our artist'],
        'art/walls/*/*.png' => ['by' => 'our artist'],
        'art/items/*/*.png' => ['by' => 'our artist'],
    ]));
}

it('doubles building, wall and furniture art drawn at half size, and lists it by slug and part', function () {
    $root = scratchArt();
    claimAll($root);
    // The library is 7 × 5 on Lantern Street: (7 + 5) × 16 = 192 across, its bottom corner 7 × 16 in.
    File::ensureDirectoryExists("{$root}/art/buildings");
    File::put("{$root}/art/buildings/library.png", standing(192, 112, 60));
    File::ensureDirectoryExists("{$root}/art/walls/plaster");
    File::put("{$root}/art/walls/plaster/left.png", panel(true));
    File::put("{$root}/art/walls/plaster/right-door.png", panel(false));
    File::ensureDirectoryExists("{$root}/art/items/love-seat");
    // A 2 × 1 love seat: 48 across, its bottom corner 32 in at facing 0, 16 in when turned.
    File::put("{$root}/art/items/love-seat/0.png", standing(48, 32, 20));
    File::put("{$root}/art/items/love-seat/1.png", standing(48, 16, 20));
    $this->artisan('hushfield:build-assets')->assertSuccessful();

    $index = builtIndex();

    expect($index['sizes'])->toMatchArray([
        'buildings/library' => [384, 312],
        'walls/plaster/left' => [32, 112],
        'walls/plaster/right-door' => [32, 112],
        'items/love-seat/0' => [96, 88],
        'items/love-seat/1' => [96, 88],
    ])
        ->and(builtImage('buildings/library')->equals(Png::decode(standing(192, 112, 60))->scale(2)))->toBeTrue();

    $manifest = $this->getJson(route('assets.manifest'))->assertOk();
    $base = asset('assets');

    expect($manifest->json('buildings'))->toBe(['library' => "{$base}/{$index['files']['buildings/library']}"])
        ->and($manifest->json('walls'))->toBe(['plaster' => [
            'left' => "{$base}/{$index['files']['walls/plaster/left']}",
            'right-door' => "{$base}/{$index['files']['walls/plaster/right-door']}",
        ]])
        ->and(array_keys($manifest->json('items.love-seat')))->toBe([0, 1]);
});

it('refuses building, wall and furniture art that breaks its canvas rule', function (Closure $draw, string $message) {
    $root = scratchArt();
    claimAll($root);
    $draw($root);

    $this->artisan('hushfield:build-assets')
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'a building drawn at full size' => [
        function (string $root) {
            File::ensureDirectoryExists("{$root}/art/buildings");
            File::put("{$root}/art/buildings/library.png", Png::encode(Png::decode(standing(192, 112, 60))->scale(2)));
        },
        'art/buildings/library.png is already scaled up to 384×312. Export it at 100%: 192×156.',
    ],
    'a building the wrong width' => [
        function (string $root) {
            File::ensureDirectoryExists("{$root}/art/buildings");
            File::put("{$root}/art/buildings/laundromat.png", standing(192, 112, 60));
        },
        'art/buildings/laundromat.png is 192×156; it must be 144 wide.',
    ],
    'a building hanging below its footprint' => [
        function (string $root) {
            File::ensureDirectoryExists("{$root}/art/buildings");
            File::put("{$root}/art/buildings/library.png", standing(192, 112, 60, below: true));
        },
        'art/buildings/library.png has pixels below its footprint diamond at (0, 155). It stands on a 7 × 5 footprint.',
    ],
    'a building no map has' => [
        function (string $root) {
            File::ensureDirectoryExists("{$root}/art/buildings");
            File::put("{$root}/art/buildings/lighthouse.png", standing(64, 32, 60));
        },
        'art/buildings/lighthouse.png is for a building no map has.',
    ],
    'a wall panel exported at 200%' => [
        function (string $root) {
            File::ensureDirectoryExists("{$root}/art/walls/plaster");
            File::put("{$root}/art/walls/plaster/left.png", Png::encode(Png::decode(panel(true))->scale(2)));
        },
        'art/walls/plaster/left.png is already scaled up to 32×112. Export it at 100%: 16×56.',
    ],
    'a wall panel too short' => [
        function (string $root) {
            File::ensureDirectoryExists("{$root}/art/walls/plaster");
            File::put("{$root}/art/walls/plaster/right.png", panel(false, height: 40));
        },
        'art/walls/plaster/right.png is 40 tall; a wall panel must be at least 56 tall.',
    ],
    'a wall panel below the floor edge' => [
        function (string $root) {
            File::ensureDirectoryExists("{$root}/art/walls/plaster");
            File::put("{$root}/art/walls/plaster/left.png", panel(true, below: true));
        },
        'art/walls/plaster/left.png has pixels below the floor edge at (15, 55).',
    ],
    'a left panel drawn as a right one' => [
        function (string $root) {
            File::ensureDirectoryExists("{$root}/art/walls/plaster");
            File::put("{$root}/art/walls/plaster/left.png", panel(false));
        },
        'art/walls/plaster/left.png has pixels below the floor edge',
    ],
    'furniture not a whole number of tiles across' => [
        function (string $root) {
            File::ensureDirectoryExists("{$root}/art/items/stool");
            File::put("{$root}/art/items/stool/0.png", standing(40, 16, 10));
        },
        'art/items/stool/0.png is 40 wide; it must be (wide + deep) × 16',
    ],
    'furniture hanging below any footprint' => [
        function (string $root) {
            File::ensureDirectoryExists("{$root}/art/items/stool");
            File::put("{$root}/art/items/stool/0.png", standing(32, 16, 10, below: true));
        },
        'art/items/stool/0.png has pixels below its footprint diamond',
    ],
    'the old flat furniture name' => [
        function (string $root) {
            File::ensureDirectoryExists("{$root}/art/items");
            File::put("{$root}/art/items/stool-0.png", standing(32, 16, 10));
        },
        "art/items/stool-0.png isn't a file the build knows.",
    ],
]);

it('reports how much of an item\'s art is built, counting facings the game mirrors or borrows', function () {
    $root = scratchArt();
    claimAll($root);
    File::ensureDirectoryExists("{$root}/art/items/love-seat");
    File::ensureDirectoryExists("{$root}/art/items/corner-sofa");
    File::ensureDirectoryExists("{$root}/art/items/wrong-table");
    // One facing covers a plain block: the game mirrors and borrows the rest.
    File::put("{$root}/art/items/love-seat/0.png", standing(48, 32, 20));
    // An L only has the tiles of the facing it was drawn for.
    File::put("{$root}/art/items/corner-sofa/0.png", standing(64, 32, 20));
    // Drawn 1 × 1, but the item is 2 × 1: the game won't draw it.
    File::put("{$root}/art/items/wrong-table/0.png", standing(32, 16, 20));
    $this->artisan('hushfield:build-assets')->assertSuccessful();

    $furniture = fn (string $slug, int $wide, int $deep, ?array $shape = null): Item => new Item([
        'slug' => $slug, 'slot' => ItemSlot::Furniture, 'wide' => $wide, 'deep' => $deep, 'shape' => $shape,
    ]);
    $art = app(ItemArt::class);

    expect($art->status($furniture('love-seat', 2, 1)))->toBe(ArtStatus::Complete)
        ->and($art->status($furniture('corner-sofa', 2, 2, ['##', '#.'])))->toBe(ArtStatus::Partial)
        ->and($art->status($furniture('wrong-table', 2, 1)))->toBe(ArtStatus::Partial)
        ->and($art->status($furniture('standard-bed', 2, 2)))->toBe(ArtStatus::None);
});
