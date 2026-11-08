<?php

/*
 * Draws the templates in this folder: for docs/art/tiles.md, a full cube and
 * a half slab, in tiles/; for docs/art/furniture.md, one per size and facing,
 * in furniture/; for docs/art/buildings.md, one per building on the maps, in
 * buildings/; for docs/art/walls.md, the panels each wall style on the maps
 * needs, at its height, in walls/{style}/; for docs/art/avatars.md, the
 * body, clothes and accessory templates for each facing and the palette, in
 * avatars/. Each is at 100% in art pixels, with a preview for the guides
 * under previews/. Run from the repository root:
 *
 *     php docs/art/templates/make-templates.php
 *
 * To add a furniture size, add it to PIECES. Buildings and wall styles are
 * read from the maps, so a new one gets its templates on the next run. Uses
 * the game's own Footprint, so shapes turn exactly as they do in the game.
 * Avatar templates are read from resources/art/avatars: the frame size,
 * animations, anchor colours and palette from rig.json, and the figure shown
 * faded from the base body there (the stand-in until hers replaces it), so
 * run this again whenever her base body changes.
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
// A ground tile's lit left side and shaded right side.
const SIDE_LIT = [150, 170, 196, 255];
const SIDE_SHADE = [120, 140, 168, 255];

foreach (['full' => false, 'half' => true] as $height => $half) {
    save("tiles/{$height}", tile($half));
}

foreach (PIECES as $name => [$wide, $deep, $shape]) {
    foreach (range(0, 3) as $facing) {
        $lies = (new Footprint(0, 0, $wide, $deep, $shape))->turned($facing);
        save("furniture/{$name}-facing-{$facing}", template($lies, $facing));
    }
}

foreach (buildings() as $slug => [$footprint, $door, $walls]) {
    // Room above the placeholder's walls for a roof; drawing taller is fine.
    save("buildings/{$slug}", template($footprint, null, $door, $walls + 48));
}

foreach (wallStyles() as $style => [$parts, $face]) {
    foreach ($parts as $part) {
        save("walls/{$style}/{$part}", panel(str_starts_with($part, 'left'), str_ends_with($part, '-door'), $face));
    }
}

$rig = json_decode((string) file_get_contents(__DIR__.'/../../../resources/art/avatars/rig.json'), true, flags: JSON_THROW_ON_ERROR);

foreach (['front', 'back'] as $facing) {
    $base = Png::decode((string) file_get_contents(__DIR__."/../../../resources/art/avatars/base/{$facing}.png"));
    $anchors = Png::decode((string) file_get_contents(__DIR__."/../../../resources/art/avatars/base/{$facing}-anchors.png"));
    save("avatars/body-{$facing}", figure($rig, $base, $anchors, true), 4);
    save("avatars/clothes-{$facing}", figure($rig, $base, $anchors, false), 4);
    save("avatars/accessory-{$facing}", accessory($rig, $base, $anchors));
}

save('avatars/palette', palette($rig));

/** Writes a template at 100%, and its preview for the guides. */
function save(string $name, Image $template, int $scale = PREVIEW_SCALE): void
{
    foreach (["{$name}.png" => $template, "previews/{$name}.png" => onChecks($template)->scale($scale)] as $path => $image) {
        @mkdir(dirname(__DIR__."/{$path}"), recursive: true);
        file_put_contents(__DIR__."/{$path}", Png::encode($image));
    }
}

/**
 * Every map in resources/maps, decoded.
 *
 * @return list<array<string, mixed>>
 */
function maps(): array
{
    return array_map(
        fn (string $file): array => json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR),
        glob(__DIR__.'/../../../resources/maps/*.json') ?: [],
    );
}

/**
 * Every wall style on the maps, by slug: the panels it needs (a side for
 * each back edge it lines, and that side's doorway where a door is on that
 * edge), and how tall its face stands in art pixels: the tallest
 * `wall_height` among the maps using it, or the game's default of 96.
 *
 * @return array<string, array{list<string>, int}>
 */
function wallStyles(): array
{
    $found = [];

    foreach (maps() as $map) {
        $props = array_column($map['properties'] ?? [], 'value', 'name');

        if (! isset($props['walls'])) {
            continue;
        }

        $sides = match ($props['wall_sides'] ?? 'both') {
            'left' => ['left'],
            'right' => ['right'],
            default => ['left', 'right'],
        };
        $parts = $sides;

        foreach ($map['layers'] as $layer) {
            foreach ($layer['name'] === 'exits' ? $layer['objects'] ?? [] : [] as $exit) {
                // The left wall runs down the column x = 0, the right along the row y = 0.
                $on = ['left' => intdiv((int) $exit['x'], 32) === 0, 'right' => intdiv((int) $exit['y'], 32) === 0];

                foreach ($sides as $side) {
                    if ($on[$side]) {
                        $parts[] = "{$side}-door";
                    }
                }
            }
        }

        [$had, $face] = $found[$props['walls']] ?? [[], 0];
        $parts = array_values(array_intersect(['left', 'right', 'left-door', 'right-door'], [...$had, ...$parts]));
        $found[$props['walls']] = [$parts, max($face, intdiv((int) ($props['wall_height'] ?? 96), 2))];
    }

    ksort($found);

    return $found;
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

    foreach (maps() as $map) {
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

/**
 * A ground tile on its 32 × 32 canvas, as resources/maps/README.md, "Ground
 * tiles", lays it out and the build's placeholder tiles draw it: a block
 * standing on the bottom of the canvas, its 32 × 16 top face on rows 0 to 15
 * for a full cube, 8 lower for a half slab. The top face is checkered in
 * quarters so its middle shows; the lit left side and shaded right side are
 * tinted; everything is outlined.
 */
function tile(bool $half): Image
{
    $top = $half ? 8 : 0;
    $shape = function (int $x, int $y) use ($top): ?string {
        if ($x < 0 || $x > 31 || $y < 0 || $y > 31) {
            return null;
        }

        // Half the block's width on this row, and of its top face.
        $block = $y < $top ? 0 : ($y <= $top + 7 ? 2 * ($y - $top + 1) : ($y <= 24 ? 16 : 16 - 2 * ($y - 24)));
        $face = $y < $top ? 0 : ($y <= $top + 7 ? 2 * ($y - $top + 1) : max(0, 16 - 2 * ($y - $top - 8)));
        $from = abs($x - 15.5) + 0.5;

        return $from > $block ? null : ($from <= $face ? 'top' : ($x < 16 ? 'left' : 'right'));
    };

    $pixels = '';

    for ($y = 0; $y < 32; $y++) {
        for ($x = 0; $x < 32; $x++) {
            $part = $shape($x, $y);
            $outline = $part !== null && ($shape($x - 1, $y) !== $part || $shape($x + 1, $y) !== $part
                || $shape($x, $y - 1) !== $part || $shape($x, $y + 1) !== $part);
            $colour = match (true) {
                $part === null => null,
                $outline => EDGE,
                $part === 'top' => (($x < 16) xor ($y < $top + 8)) ? DARK : LIGHT,
                $part === 'left' => SIDE_LIT,
                default => SIDE_SHADE,
            };

            $pixels .= $colour === null ? "\0\0\0\0" : pack('C4', ...$colour);
        }
    }

    return new Image(32, 32, $pixels);
}

/**
 * A wall panel, 16 wide, as resources/maps/README.md, "Wall panels", lays it
 * out: the face standing `face` above a floor edge that climbs 8 across the
 * panel (up to the right on `left`, down to the right on `right`), outlined,
 * with nothing below the edge. A doorway's opening, from the floor up to
 * 32 between narrow jambs as the game's placeholder draws it, is orange.
 */
function panel(bool $left, bool $door, int $face): Image
{
    [$width, $climb, $jamb, $opening] = [16, 8, 2, 32];
    $height = $face + $climb;
    $pixels = '';

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            // The floor edge at this column's middle, as the build measures it.
            $edge = (int) floor($height - ($left ? $x + 0.5 : $width - $x - 0.5) * $climb / $width);
            $up = $edge - $y;

            $colour = match (true) {
                $up < 0 || $up > $face => null,
                $up === 0 || $up === $face || $x === 0 || $x === $width - 1 => EDGE,
                $door && $up <= $opening && $x >= $jamb && $x < $width - $jamb => FRONT,
                default => intdiv($up - 1, 16) % 2 === 0 ? LIGHT : DARK,
            };

            $pixels .= $colour === null ? "\0\0\0\0" : pack('C4', ...$colour);
        }
    }

    return new Image($width, $height, $pixels);
}

/**
 * An avatar strip, one frame per frame of the rig, each frame tinted for its
 * animation (idle blue, walk green, sit yellow, wave pink, a frame lighter or
 * darker than the next), its left edge and the ground under the feet marked,
 * with the base body faded over it. The body template adds each frame's
 * anchors at full colour, from the base's anchors strip.
 *
 * @param  array<string, mixed>  $rig
 */
function figure(array $rig, Image $base, Image $anchors, bool $withAnchors): Image
{
    [$w, $h] = $rig['frame'];
    $tints = ['idle' => [120, 160, 220], 'walk' => [120, 200, 130], 'sit' => [220, 200, 100], 'wave' => [220, 130, 170]];
    $of = [];

    foreach ($rig['animations'] as $name => $animation) {
        foreach ($animation['frames'] as $frame) {
            $of[$frame] = $tints[$name] ?? [180, 180, 180];
        }
    }

    $ground = groundRow($anchors, $rig, $w, $h);
    $pixels = '';

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $base->width; $x++) {
            $frame = intdiv($x, $w);
            $pixels .= match (true) {
                $withAnchors && $anchors->alphaAt($x, $y) === 255 => substr($anchors->pixels, ($y * $anchors->width + $x) * 4, 4),
                $base->alphaAt($x, $y) === 255 => substr($base->pixels, ($y * $base->width + $x) * 4, 3).chr(140),
                $y === $ground || $x % $w === 0 => pack('C4', ...EDGE),
                default => pack('C4', ...[...$of[$frame] ?? [180, 180, 180], $frame % 2 === 0 ? 56 : 88]),
            };
        }
    }

    return new Image($base->width, $h, $pixels);
}

/**
 * One frame for hair, hats, glasses, pins and held things: the base body's
 * first frame faded, the ground marked, and the head, chest and hand anchors
 * those are placed on at full colour.
 *
 * @param  array<string, mixed>  $rig
 */
function accessory(array $rig, Image $base, Image $anchors): Image
{
    [$w, $h] = $rig['frame'];
    $marked = array_map(fn (string $hex): string => (string) hex2bin($hex), array_intersect_key($rig['anchors'], array_flip(['head', 'chest', 'hand'])));
    $ground = groundRow($anchors, $rig, $w, $h);
    $pixels = '';

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $at = ($y * $base->width + $x) * 4;
            $anchor = $anchors->alphaAt($x, $y) === 255 ? substr($anchors->pixels, $at, 3) : null;
            $pixels .= match (true) {
                $anchor !== null && in_array($anchor, $marked, true) => $anchor.chr(255),
                $base->alphaAt($x, $y) === 255 => substr($base->pixels, $at, 3).chr(140),
                $y === $ground => pack('C4', ...EDGE),
                default => "\0\0\0\0",
            };
        }
    }

    return new Image($w, $h, $pixels);
}

/**
 * The row under the feet in the base's first frame, the feet anchor's row
 * plus one, or the bottom row if the feet are on it.
 *
 * @param  array<string, mixed>  $rig
 */
function groundRow(Image $anchors, array $rig, int $w, int $h): int
{
    $feet = (string) hex2bin($rig['anchors']['feet']);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($anchors->alphaAt($x, $y) === 255 && substr($anchors->pixels, ($y * $anchors->width + $x) * 4, 3) === $feet) {
                return min($h - 1, $y + 1);
            }
        }
    }

    return $h - 1;
}

/**
 * The colours to paint with, 8 × 8 each: the skin ramp on the first row, the
 * recolour ramp on the second, light to dark, and the anchor colours on the
 * third, in rig.json's order (head, chest, hand, hip, feet).
 *
 * @param  array<string, mixed>  $rig
 */
function palette(array $rig): Image
{
    $rows = [$rig['palette']['skin'], $rig['palette']['recolour'], array_values($rig['anchors'])];
    $width = 8 * max(array_map('count', $rows));
    $pixels = '';

    for ($y = 0; $y < 8 * count($rows); $y++) {
        for ($x = 0; $x < $width; $x++) {
            $hex = $rows[intdiv($y, 8)][intdiv($x, 8)] ?? null;
            $pixels .= $hex === null ? "\0\0\0\0" : hex2bin($hex).chr(255);
        }
    }

    return new Image($width, 8 * count($rows), $pixels);
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
