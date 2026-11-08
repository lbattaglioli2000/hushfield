<?php

/*
 * Draws stand-in avatar art, so the game's avatars can be built and tested
 * before the artist draws hers (docs/art/avatars.md, docs/assets-plan.md §14):
 * a base body with its anchors, short hair, and one of each seeded wearable,
 * every moving piece the rig has. Plain shapes, nothing to keep. Run from the
 * repository root:
 *
 *     php docs/art/templates/make-avatar-standins.php
 *
 * Writes into resources/art/avatars/ and resources/art/items/{slug}/; her art
 * replaces each file under the same name.
 */

use App\Domain\Assets\Image;
use App\Domain\Assets\Png;

require __DIR__.'/../../../vendor/autoload.php';

const W = 40;
const H = 48;
const FRAMES = 9;

/** The anchors' marker colours, as rig.json names them. */
const ANCHOR_COLOURS = ['head' => 'ff0000', 'chest' => '00ff00', 'hand' => '0000ff', 'hip' => 'ffff00', 'feet' => 'ff00ff'];

/** A strip being drawn: FRAMES (or one) frames side by side, colours as hex. */
final class Canvas
{
    /** @var array<int, string> pixel index => hex */
    private array $pixels = [];

    public int $frame = 0;

    public function __construct(public readonly int $frames = FRAMES) {}

    /** A filled box, inclusive, in the current frame; `outline` draws its edge in another colour. */
    public function box(int $x0, int $y0, int $x1, int $y1, string $fill, ?string $outline = null): void
    {
        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = $x0; $x <= $x1; $x++) {
                $edge = $outline !== null && ($x === $x0 || $x === $x1 || $y === $y0 || $y === $y1);
                $this->dot($x, $y, $edge ? $outline : $fill);
            }
        }
    }

    public function dot(int $x, int $y, string $hex): void
    {
        if ($x >= 0 && $x < W && $y >= 0 && $y < H) {
            $this->pixels[$y * W * $this->frames + $this->frame * W + $x] = $hex;
        }
    }

    public function image(): Image
    {
        $bytes = '';

        for ($i = 0; $i < W * $this->frames * H; $i++) {
            $hex = $this->pixels[$i] ?? null;
            $bytes .= $hex === null ? "\0\0\0\0" : hex2bin($hex).chr(255);
        }

        return new Image(W * $this->frames, H, $bytes);
    }
}

/**
 * The body in one frame of one facing, in art pixels: how far the upper body
 * sinks, its parts as boxes, and its anchors. It stands 29 pixels from the
 * top of the head (row 18) to the feet (row 46), short enough for a doorway.
 * Frames are idle 1–2, walk 1–4, sit, wave 1–2 (rig.json). Forward is screen
 * left facing front, right facing back.
 *
 * @return array{bob: int, legs: list<array{int, int, int, int}>, near: array{int, int, int, int}, far: array{int, int, int, int}, sit: bool, anchors: array<string, array{int, int}>}
 */
function pose(string $facing, int $frame): array
{
    $forward = $facing === 'front' ? -1 : 1;
    $bob = [0, 1, 0, -1, 0, -1, 6, 0, 0][$frame];
    $step = [0, 0, 1, 0, -1, 0, 0, 0, 0][$frame] * $forward;
    $sit = $frame === 6;

    // Seated, a thigh comes forward at the hip and a shin goes down to the ground.
    $legs = $sit
        ? ($forward < 0 ? [[11, 40, 23, 42], [11, 40, 13, 46]] : [[17, 40, 29, 42], [27, 40, 29, 46]])
        : [[16 + $step, 37, 18 + $step, 46], [22 - $step, 37, 24 - $step, 46]];

    // The arms swing against the legs.
    $swing = $step;
    $near = [14 - $swing, 28 + $bob, 15 - $swing, 35 + $bob];
    $far = [25 + $swing, 28 + $bob, 26 + $swing, 35 + $bob];

    if ($frame === 7) {
        $far = [25, 19, 26, 27];
    } elseif ($frame === 8) {
        $far = [26, 18, 27, 26];
    }

    $hand = $facing === 'front' ? [$near[0], $near[3]] : [$far[0], $far[3]];

    if ($facing === 'back' && $frame >= 7) {
        $hand = [$far[0], $far[1]];
    }

    return [
        'bob' => $bob,
        'legs' => $legs,
        'near' => $near,
        'far' => $far,
        'sit' => $sit,
        'anchors' => [
            'head' => [20, 18 + $bob],
            'chest' => [$facing === 'front' ? 18 : 22, 30 + $bob],
            'hand' => $hand,
            'hip' => [20, 36 + $bob],
            'feet' => [20, 46],
        ],
    ];
}

/** Writes one strip as resources/art/{path}. */
function save(string $path, Canvas $canvas): void
{
    $file = __DIR__."/../../../resources/art/{$path}";
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, Png::encode($canvas->image()));
}

/** Draws `$draw` in every frame of a strip for each facing, and saves {path}/{facing}{suffix}.png. */
function strips(string $path, callable $draw, array $facings = ['front', 'back'], int $frames = FRAMES, string $suffix = ''): void
{
    foreach ($facings as $facing) {
        $canvas = new Canvas($frames);

        foreach (range(0, $frames - 1) as $frame) {
            $canvas->frame = $frame;
            $draw($canvas, $facing, pose($facing, $frame));
        }

        save("{$path}/{$facing}{$suffix}.png", $canvas);
    }
}

// The base body, painted in pale greys: the game tints it with the resident's colour.
[$outline, $skin, $shade, $ink] = ['5a5a5a', 'f2f2f2', 'd0d0d0', '303030'];

strips('avatars/base', function (Canvas $c, string $facing, array $p) use ($outline, $skin, $shade, $ink): void {
    $b = $p['bob'];
    $c->box(...[...($facing === 'front' ? $p['far'] : $p['near']), $shade, $outline]);

    $legs = function () use ($c, $p, $skin, $outline): void {
        foreach ($p['legs'] as $leg) {
            $c->box(...[...$leg, $skin, $outline]);
        }
    };

    // Seated, the thighs come forward over the body; standing, the body sits on the legs.
    if (! $p['sit']) {
        $legs();
    }

    $c->box(16, 27 + $b, 24, $p['sit'] ? 36 + $b : 37, $skin, $outline);

    if ($p['sit']) {
        $legs();
    }

    $c->box(19, 26 + $b, 21, 26 + $b, $skin, $outline);
    $c->box(16, 18 + $b, 24, 25 + $b, $skin, $outline);

    if ($facing === 'front') {
        $c->dot(18, 21 + $b, $ink);
        $c->dot(21, 21 + $b, $ink);
        $c->box(19, 23 + $b, 20, 23 + $b, $ink);
    }

    $c->box(...[...($facing === 'front' ? $p['near'] : $p['far']), $skin, $outline]);
});

strips('avatars/base', function (Canvas $c, string $facing, array $p): void {
    foreach ($p['anchors'] as $name => [$x, $y]) {
        $c->dot($x, $y, ANCHOR_COLOURS[$name]);
    }
}, suffix: '-anchors');

// Rigid layers: one frame per facing, drawn where the first frame puts the anchor.
strips('avatars/hair/short', function (Canvas $c, string $facing): void {
    $c->box(16, 16, 24, 19, '6b4226', '3e2615');
    $c->box(16, 19, $facing === 'front' ? 17 : 24, $facing === 'front' ? 22 : 23, '6b4226', '3e2615');
}, frames: 1);

strips('items/wool-cap', function (Canvas $c): void {
    $c->box(15, 14, 25, 19, 'c0392b', '6e1f17');
    $c->box(15, 18, 25, 19, '8e2a20');
    $c->box(20, 12, 20, 13, 'f0e6d2');
}, frames: 1);

strips('items/round-spectacles', function (Canvas $c): void {
    $c->box(17, 20, 19, 22, 'a8d8ff', '222222');
    $c->box(21, 20, 23, 22, 'a8d8ff', '222222');
    $c->dot(20, 21, '222222');
}, ['front'], frames: 1);

strips('items/resident-pin', function (Canvas $c): void {
    $c->box(17, 29, 18, 30, 'f5c542');
}, ['front'], frames: 1);

strips('items/brass-lantern', function (Canvas $c, string $facing, array $p): void {
    [$x, $y] = $p['anchors']['hand'];
    $c->dot($x, $y + 1, '3a2a10');
    $c->box($x - 1, $y + 2, $x + 1, $y + 6, 'ffd34d', '8a5a0b');
}, frames: 1);

// Drawn layers: every frame, following the body.
strips('items/municipal-raincoat', function (Canvas $c, string $facing, array $p): void {
    [$fill, $edge] = ['e8c547', '8a6d1a'];
    $b = $p['bob'];
    $c->box(...[...($facing === 'front' ? $p['far'] : $p['near']), 'c9a83a', $edge]);
    $c->box(15, 27 + $b, 25, $p['sit'] ? 36 + $b : 40, $fill, $edge);

    if ($facing === 'front') {
        foreach ([30, 33, 36] as $y) {
            $c->dot(20, $y + $b, $edge);
        }
    }

    $c->box(...[...($facing === 'front' ? $p['near'] : $p['far']), $fill, $edge]);
});

strips('items/quiet-boots', function (Canvas $c, string $facing, array $p): void {
    foreach ($p['legs'] as [$x0, $y0, $x1, $y1]) {
        if ($y1 === 46 && $x1 - $x0 < 4) {
            $c->box($x0 - ($facing === 'front' ? 1 : 0), 43, $x1 + ($facing === 'back' ? 1 : 0), 46, '3b3550', '1c1828');
        }
    }
});

strips('items/moth-wing-cloak', function (Canvas $c, string $facing, array $p): void {
    $b = $p['bob'];

    if ($facing === 'front') {
        $c->box(15, 26 + $b, 25, 28 + $b, '8b7d6b', '4a4036');
        $c->box(20, 28 + $b, 20, 29 + $b, 'e0d4b0');
    } else {
        $c->box(13, 26 + $b, 27, $p['sit'] ? 42 : 41, '8b7d6b', '4a4036');

        foreach ([[16, 31], [23, 31], [20, 36]] as [$x, $y]) {
            $c->box($x, $y + $b, $x + 1, $y + 1 + $b, 'e0d4b0');
        }
    }
});

// The cloak's wings, behind the body when it faces front.
strips('items/moth-wing-cloak', function (Canvas $c, string $facing, array $p): void {
    $b = $p['bob'];
    $c->box(8, 26 + $b, 32, $p['sit'] ? 42 : 43, '8b7d6b', '4a4036');

    foreach ([[10, 30], [29, 30], [11, 37], [28, 37]] as [$x, $y]) {
        $c->box($x, $y + $b, $x + 1, $y + 1 + $b, 'e0d4b0');
    }
}, ['front'], suffix: '-behind');

echo "Drew the stand-in avatar art in resources/art.\n";
