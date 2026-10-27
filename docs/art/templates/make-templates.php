<?php

/*
 * Draws the footprint templates in this folder: for docs/art/furniture.md,
 * one per size and facing; for docs/art/buildings.md, one per building on
 * the maps, in buildings/. Each is at 100% in art pixels, with an 8× preview
 * for the guides. Run from the repository root:
 *
 *     php docs/art/templates/make-templates.php
 *
 * To add a size, add it to PIECES. Buildings are read from the maps, so a new
 * one gets its template on the next run. Uses the game's own Footprint, so shapes
 * turn exactly as they do in the game.
 */

use App\Domain\Assets\Image;
use App\Domain\Assets\Png;
use App\Domain\World\Footprint;

require __DIR__.'/../../../vendor/autoload.php';

/** Name => [wide, deep, shape or null]. */
const PIECES = [
    '1x1' => [1, 1, null],
    '2x1' => [2, 1, null],
    '2x2' => [2, 2, null],
    '3x1' => [3, 1, null],
    '3x2' => [3, 2, null],
    '3x3' => [3, 3, null],
    '4x1' => [4, 1, null],
    '4x2' => [4, 2, null],
    '4x3' => [4, 3, null],
    '4x4' => [4, 4, null],
    'corner-sofa' => [2, 2, ['##', '#.']],
    'long-corner-sofa' => [4, 2, ['####', '...#']],
];

/** Art pixels of empty canvas above the footprint, as room to draw. */
const HEADROOM = 40;

const PREVIEW_SCALE = 8;

// RGBA: the two tile shades, the outline, the front edge, an empty tile.
const LIGHT = [196, 214, 232, 255];
const DARK = [168, 192, 216, 255];
const EDGE = [72, 96, 128, 255];
const FRONT = [232, 128, 40, 255];
const GAP = [236, 120, 120, 120];

$out = __DIR__;
@mkdir("{$out}/previews");

foreach (PIECES as $name => [$wide, $deep, $shape]) {
    foreach (range(0, 3) as $facing) {
        $lies = (new Footprint(0, 0, $wide, $deep, $shape))->turned($facing);
        $template = template($lies, $facing);
        file_put_contents("{$out}/{$name}-facing-{$facing}.png", Png::encode($template));
        file_put_contents("{$out}/previews/{$name}-facing-{$facing}.png", Png::encode(onChecks($template)->scale(PREVIEW_SCALE)));
    }
}

@mkdir("{$out}/buildings");
@mkdir("{$out}/previews/buildings");

foreach (buildings() as $slug => [$footprint, $door, $walls]) {
    // Room above the placeholder's walls for a roof; drawing taller is fine.
    $template = template($footprint, null, $door, $walls + 48);
    file_put_contents("{$out}/buildings/{$slug}.png", Png::encode($template));
    file_put_contents("{$out}/previews/buildings/{$slug}.png", Png::encode(onChecks($template)->scale(PREVIEW_SCALE)));
}

/**
 * Every building on the maps, by slug: its footprint, its door's tile within
 * it, and the placeholder's wall height in art pixels.
 *
 * @return array<string, array{Footprint, array{int, int}, int}>
 */
function buildings(): array
{
    $found = [];

    foreach (glob(__DIR__.'/../../../resources/maps/*.json') ?: [] as $file) {
        /** @var array{layers: list<array{type: string, name: string, objects?: list<array<string, mixed>>}>} $map */
        $map = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $objects = array_merge(...array_map(fn (array $layer): array => $layer['objects'] ?? [], $map['layers']));
        $exits = array_column(array_filter($objects, fn (array $o): bool => ($o['type'] ?? '') === 'exit'), null, 'name');

        foreach ($map['layers'] as $layer) {
            if ($layer['name'] !== 'buildings') {
                continue;
            }

            foreach ($layer['objects'] ?? [] as $b) {
                $props = array_column($b['properties'] ?? [], 'value', 'name');
                $door = $exits[$props['door']];
                [$x, $y] = [intdiv((int) $b['x'], 32), intdiv((int) $b['y'], 32)];
                $found[$b['name']] = [
                    new Footprint(0, 0, intdiv((int) $b['width'], 32), intdiv((int) $b['height'], 32)),
                    [intdiv((int) $door['x'], 32) - $x, intdiv((int) $door['y'], 32) - $y],
                    intdiv((int) ($props['height'] ?? 64), 2),
                ];
            }
        }
    }

    ksort($found);

    return $found;
}

/**
 * A canvas (wide + deep) × 16 across with the footprint diamond at the
 * bottom, as resources/maps/README.md, "Buildings, furniture and walls",
 * lays it out: its bottom corner on the bottom row, wide × 16 from the left.
 * Tiles are checkered and empty tiles of a shape are red. For furniture the
 * edge the piece faces is orange; a building (no facing) has its door's tile
 * orange instead.
 *
 * @param  array{int, int}|null  $door
 */
function template(Footprint $lies, ?int $facing, ?array $door = null, int $headroom = HEADROOM): Image
{
    [$w, $d] = [$lies->wide, $lies->deep];
    $width = ($w + $d) * 16;
    $height = ($w + $d) * 8 + $headroom;
    $top = $height - ($w + $d) * 8;
    $inside = function (int $x, int $y) use ($w, $d, $width, $height, $top): ?array {
        if ($x < 0 || $x >= $width || $y < 0 || $y >= $height) {
            return null;
        }

        // Rows the diamond covers in this column: 2:1 edges, two columns to a row.
        $low = $height - 1 - intdiv((int) (abs($x + 0.5 - $w * 16) - 0.5), 2);
        $high = $top + intdiv((int) (abs($x + 0.5 - $d * 16) - 0.5), 2);

        if ($y < $high || $y > $low) {
            return null;
        }

        // Grid position of the pixel's centre: +x runs down-right, +y down-left.
        $across = ($x + 0.5 - $d * 16) / 16;
        $down = ($y + 0.5 - $top) / 8;

        return [($across + $down) / 2, ($down - $across) / 2];
    };

    $pixels = '';

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $grid = $inside($x, $y);

            if ($grid === null) {
                $pixels .= "\0\0\0\0";

                continue;
            }

            [$gx, $gy] = $grid;
            $tx = max(0, min($w - 1, (int) floor($gx)));
            $ty = max(0, min($d - 1, (int) floor($gy)));
            $outline = $inside($x - 1, $y) === null || $inside($x + 1, $y) === null
                || $inside($x, $y - 1) === null || $inside($x, $y + 1) === null;

            if ($outline) {
                // The nearest side of the block; the piece faces +y at 0, −x at 1, −y at 2, +x at 3.
                $sides = [0 => $d - $gy, 1 => $gx, 2 => $gy, 3 => $w - $gx];
                $colour = array_keys($sides, min($sides))[0] === $facing ? FRONT : EDGE;
            } elseif ($door === [$tx, $ty]) {
                $colour = FRONT;
            } elseif (! $lies->covers($tx, $ty)) {
                $colour = GAP;
            } else {
                $colour = ($tx + $ty) % 2 === 0 ? LIGHT : DARK;
            }

            $pixels .= pack('C4', ...$colour);
        }
    }

    return new Image($width, $height, $pixels);
}

/** The template over a light checkerboard, so its transparency shows in the guide. */
function onChecks(Image $image): Image
{
    $pixels = '';

    foreach (str_split($image->pixels, 4) as $i => $pixel) {
        [$r, $g, $b, $a] = array_values((array) unpack('C4', $pixel));
        [$x, $y] = [$i % $image->width, intdiv($i, $image->width)];
        $check = (intdiv($x, 2) + intdiv($y, 2)) % 2 === 0 ? 250 : 232;
        $mix = fn (int $c): int => (int) round(($c * $a + $check * (255 - $a)) / 255);
        $pixels .= pack('C4', $mix($r), $mix($g), $mix($b), 255);
    }

    return new Image($image->width, $image->height, $pixels);
}
