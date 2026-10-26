<?php

/*
 * Draws the footprint templates in this folder, for docs/art/furniture.md:
 * one per size and facing, at 100% in art pixels, and an 8× preview of each
 * for the guide. Run from the repository root:
 *
 *     php docs/art/templates/make-templates.php
 *
 * To add a size, add it to PIECES. Uses the game's own Footprint, so shapes
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
    'corner-sofa' => [2, 2, ['##', '#.']],
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

/**
 * A canvas (wide + deep) × 16 across with the footprint diamond at the
 * bottom, as resources/maps/README.md, "Buildings, furniture and walls",
 * lays it out: its bottom corner on the bottom row, wide × 16 from the left.
 * Tiles are checkered, empty tiles of a shape are red, and the edge the piece
 * faces is orange.
 */
function template(Footprint $lies, int $facing): Image
{
    [$w, $d] = [$lies->wide, $lies->deep];
    $width = ($w + $d) * 16;
    $height = ($w + $d) * 8 + HEADROOM;
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
