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

const W = 48;
const H = 72;
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
 * sinks, its parts as boxes, and its anchors. Frames are idle 1–2, walk 1–4,
 * sit, wave 1–2 (rig.json). Forward is screen left facing front, right facing back.
 *
 * @return array{bob: int, legs: list<array{int, int, int, int}>, near: array{int, int, int, int}, far: array{int, int, int, int}, sit: bool, anchors: array<string, array{int, int}>}
 */
function pose(string $facing, int $frame): array
{
    $forward = $facing === 'front' ? -1 : 1;
    $bob = [0, 1, 0, -1, 0, -1, 9, 0, 0][$frame];
    $step = [0, 0, 2, 0, -2, 0, 0, 0, 0][$frame] * $forward;
    $sit = $frame === 6;

    $legs = $sit
        ? ($forward < 0 ? [[12, 59, 27, 62], [12, 59, 15, 69]] : [[20, 59, 35, 62], [32, 59, 35, 69]])
        : [[19 + $step, 52, 22 + $step, 69], [25 - $step, 52, 28 - $step, 69]];

    // The arms swing against the legs: the one on the side of the leading leg trails.
    $swing = intdiv($step, 2);
    $near = [15 - $swing, 38 + $bob, 17 - $swing, 50 + $bob];
    $far = [30 + $swing, 38 + $bob, 32 + $swing, 50 + $bob];

    if ($frame === 7) {
        $far = [31, 25, 33, 37];
    } elseif ($frame === 8) {
        $far = [33, 24, 35, 36];
    }

    $hand = $facing === 'front' ? [$near[0] + 1, $near[3]] : [$far[0] + 1, $far[3]];

    if ($facing === 'back' && $frame >= 7) {
        $hand = [$far[0] + 1, $far[1]];
    }

    return [
        'bob' => $bob,
        'legs' => $legs,
        'near' => $near,
        'far' => $far,
        'sit' => $sit,
        'anchors' => [
            'head' => [24, 22 + $bob],
            'chest' => [$facing === 'front' ? 21 : 26, 41 + $bob],
            'hand' => $hand,
            'hip' => [24, 52 + $bob],
            'feet' => [24, 69],
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

    $c->box(18, 37 + $b, 29, $p['sit'] ? 52 + $b : 53, $skin, $outline);

    if ($p['sit']) {
        $legs();
    }
    $c->box(22, 35 + $b, 25, 37 + $b, $skin, $outline);
    $c->box(17, 22 + $b, 30, 35 + $b, $skin, $outline);

    if ($facing === 'front') {
        $c->dot(20, 28 + $b, $ink);
        $c->dot(24, 28 + $b, $ink);
        $c->box(21, 32 + $b, 22, 32 + $b, $ink);
    }

    $c->box(...[...($facing === 'front' ? $p['near'] : $p['far']), $skin, $outline]);
});

strips('avatars/base', function (Canvas $c, string $facing, array $p): void {
    foreach ($p['anchors'] as $name => [$x, $y]) {
        $c->dot($x, $y, ANCHOR_COLOURS[$name]);
    }
}, suffix: '-anchors');

// Rigid layers: one frame per facing, drawn where frame 1 puts the anchor.
strips('avatars/hair/short', function (Canvas $c, string $facing): void {
    $c->box(16, 20, 31, 25, '6b4226', '3e2615');
    $c->box(16, 25, $facing === 'front' ? 18 : 31, $facing === 'front' ? 29 : 31, '6b4226', '3e2615');
}, frames: 1);

strips('items/wool-cap', function (Canvas $c): void {
    $c->box(16, 18, 31, 25, 'c0392b', '6e1f17');
    $c->box(16, 24, 31, 25, '8e2a20');
    $c->box(23, 16, 24, 17, 'f0e6d2', 'c9bda6');
}, frames: 1);

strips('items/round-spectacles', function (Canvas $c): void {
    $c->box(18, 27, 21, 30, 'a8d8ff', '222222');
    $c->box(23, 27, 26, 30, 'a8d8ff', '222222');
    $c->dot(22, 28, '222222');
}, ['front'], frames: 1);

strips('items/resident-pin', function (Canvas $c): void {
    $c->box(20, 40, 22, 42, 'f5c542', '9a7414');
}, ['front'], frames: 1);

strips('items/brass-lantern', function (Canvas $c, string $facing, array $p): void {
    [$x, $y] = $p['anchors']['hand'];
    $c->dot($x, $y + 1, '3a2a10');
    $c->dot($x - 1, $y + 2, '3a2a10');
    $c->dot($x + 1, $y + 2, '3a2a10');
    $c->box($x - 2, $y + 3, $x + 2, $y + 9, 'ffd34d', '8a5a0b');
    $c->box($x - 2, $y + 9, $x + 2, $y + 9, 'b8860b');
}, frames: 1);

// Drawn layers: every frame, following the body.
strips('items/municipal-raincoat', function (Canvas $c, string $facing, array $p): void {
    [$fill, $edge] = ['e8c547', '8a6d1a'];
    $b = $p['bob'];
    $c->box(...[...($facing === 'front' ? $p['far'] : $p['near']), 'c9a83a', $edge]);
    $c->box(17, 37 + $b, 30, $p['sit'] ? 52 + $b : 57, $fill, $edge);

    if ($facing === 'front') {
        foreach ([41, 45, 49] as $y) {
            $c->dot(24, $y + $b, $edge);
        }
    }

    $c->box(...[...($facing === 'front' ? $p['near'] : $p['far']), $fill, $edge]);
});

strips('items/quiet-boots', function (Canvas $c, string $facing, array $p): void {
    foreach ($p['legs'] as [$x0, $y0, $x1, $y1]) {
        if ($y1 === 69) {
            $c->box($x0 - ($facing === 'front' ? 1 : 0), 64, $x1 + ($facing === 'back' ? 1 : 0), 69, '3b3550', '1c1828');
        }
    }
});

strips('items/moth-wing-cloak', function (Canvas $c, string $facing, array $p): void {
    $b = $p['bob'];
    $bottom = $p['sit'] ? 58 : 60;

    if ($facing === 'front') {
        $c->box(16, 36 + $b, 31, 39 + $b, '8b7d6b', '4a4036');
        $c->box(23, 39 + $b, 24, 40 + $b, 'e0d4b0', '4a4036');
    } else {
        $c->box(14, 36 + $b, 33, $bottom, '8b7d6b', '4a4036');

        foreach ([[19, 44], [28, 44], [23, 51]] as [$x, $y]) {
            $c->box($x, $y + $b, $x + 1, $y + 1 + $b, 'e0d4b0');
        }
    }
});

// The cloak's wings, behind the body when it faces front.
strips('items/moth-wing-cloak', function (Canvas $c, string $facing, array $p): void {
    $b = $p['bob'];
    $c->box(8, 36 + $b, 39, $p['sit'] ? 58 : 62, '8b7d6b', '4a4036');

    foreach ([[11, 42], [34, 42], [12, 52], [33, 52]] as [$x, $y]) {
        $c->box($x, $y + $b, $x + 2, $y + 2 + $b, 'e0d4b0');
    }
}, ['front'], suffix: '-behind');

echo "Drew the stand-in avatar art in resources/art.\n";
