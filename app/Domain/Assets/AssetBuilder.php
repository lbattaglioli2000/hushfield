<?php

namespace App\Domain\Assets;

use Closure;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use stdClass;

/**
 * Builds what ships from the artist's sources in resources/art (see
 * docs/assets-plan.md §5 and resources/maps/README.md, "Art"): packs the
 * ground tiles into one sheet in tiles.json order, multiplies every image up
 * to game size, checks each canvas, names each output by its content hash,
 * and writes the index the asset manifest is served from. With `maps`, it
 * also writes the tileset block into every map and a ground.png preview
 * beside them for Tiled.
 */
final class AssetBuilder
{
    /** A ground tile's block in game pixels: as wide as a tile, and as tall. */
    public const int TILE = 64;

    /**
     * Tiled's tileoffset for the tileset: Tiled stands a 64-tall tile on the
     * bottom of its 32-tall cell, so a full cube, its top face on the canvas's
     * top rows, is shifted down 32 to put that face on the grid.
     */
    private const int TILED_OFFSET_Y = 32;

    /** The art-pixel row each slab height's top face begins on, in its 32 × 32 source. */
    private const array SLAB_TOP = ['full' => 0, 'half' => 8];

    /** Tiles per row of the packed sheet. */
    public const int COLUMNS = 16;

    /** The tileset every map draws its ground from. */
    public const string TILESET = 'placeholder';

    /** The tileset of room pieces, written into each map with a "props" layer, and that layer's name. */
    public const string PROPS = 'props';

    /** The props tileset's first GID in a map that doesn't have it yet: well past the ground's. */
    private const int PROPS_FIRSTGID = 1001;

    /**
     * The widest an uploaded furniture animation may be once trimmed, its
     * frames side by side in game pixels: the largest texture every device
     * the game runs on loads. Any number of frames fits, if what moves is small.
     */
    private const int MAX_UPLOAD_STRIP_WIDTH = 4096;

    /** The shortest an uploaded animation may show a frame: anything quicker flickers. */
    private const int MIN_FRAME_MS = 60;

    /** Every room piece and the tiles it covers: see PropRegistry. */
    private const string PROP_REGISTRY = 'props/props.json';

    /** UI images the game uses, and their size in game pixels (null: any size). */
    private const array UI = [
        'marker' => [64, 32],
        'hover' => [64, 32],
        'resident-placeholder' => null,
    ];

    /** A building's, a wall style's or an item's slug. */
    private const string SLUG = '[a-z0-9-]+';

    /** How long each frame of each building animation shows, by building and part: see docs/art/animations.md. */
    private const string ANIMATIONS = 'buildings/animations.json';

    /** The avatar rig: frame size, animations, anchors and layer order (docs/assets-plan.md §14). */
    private const string RIG = 'avatars/rig.json';

    /** An avatar or wearable part: a facing, its anchors' strip (base only), or a part behind the body. */
    private const string AVATAR_PART = '(front|back)(-anchors|-behind)?';

    /** Wall panel parts, by side: plain, a doorway, and (not drawn yet) a window. */
    public const string WALL_PART = '(left|right)(-door|-window)?';

    /** @var list<string> What the last build let through but wants fixed. */
    private array $warnings = [];

    public function __construct(
        private readonly TileRegistry $tiles,
        private readonly PropRegistry $props,
    ) {}

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array{version: string, files: array<string, string>, sizes: array<string, array{int, int}>, animations?: array<string, array{x: int, y: int, width: int, height: int, frames: int, frame_ms: list<int>}>, avatar?: array<string, mixed>, props?: list<array{slug: string, wide: int, deep: int, animations?: array<int, array{x: int, y: int, width: int, height: int, frames: int, frame_ms: list<int>}>}|null>} */
    public function build(bool $maps = true): array
    {
        $this->warnings = [];
        $sources = (string) config('hushfield.assets.sources');
        $files = $this->sourceFiles($sources);
        $this->checkProvenance($sources, $files);

        $slots = $this->tiles->slots();
        $sheet = $this->tileSheet($sources, $files, $slots);
        $outputs = ['tiles' => $sheet];
        $buildings = null;
        $animated = [];
        $dressing = [];
        $props = $this->props->slots();
        [$pieces, $running] = $this->props($sources, $files, $props, $outputs);

        foreach ($files as $file) {
            $name = substr($file, 0, -4);

            if (preg_match('#^ui/('.self::SLUG.')\.png$#', $file, $m)) {
                $outputs[$name] = $this->ui($sources, $file, $m[1]);
            } elseif (preg_match('#^buildings/('.self::SLUG.')\.png$#', $file, $m)) {
                $buildings ??= $this->buildingFootprints();
                $outputs[$name] = $this->building($sources, $file, $buildings[$m[1]] ?? null);
            } elseif (preg_match('#^buildings/('.self::SLUG.')/'.self::SLUG.'\.png$#', $file, $m)) {
                $animated[$name] = $m[1];
            } elseif (preg_match('#^walls/'.self::SLUG.'/'.self::WALL_PART.'\.png$#', $file)) {
                $outputs[$name] = $this->wall($sources, $file, str_starts_with(basename($file), 'left'));
            } elseif (preg_match('#^items/'.self::SLUG.'/[0-3]\.png$#', $file)) {
                $outputs[$name] = $this->furniture($sources, $file);
            } elseif (preg_match('#^(avatars/(base|hair/'.self::SLUG.')|items/'.self::SLUG.')/(front|back)(-behind)?\.png$#', $file)) {
                $dressing[] = $file;
            }
        }

        $avatar = $dressing === [] ? null : $this->avatar($sources, $files, $dressing, $outputs);

        $timings = $this->animationTimings($sources, $files, array_keys($animated));
        $animations = [];

        foreach ($animated as $name => $slug) {
            $buildings ??= $this->buildingFootprints();
            [$outputs[$name], $animations[$name]] = $this->animation($sources, "{$name}.png", $slug, $files, $buildings[$slug] ?? null, $timings[substr($name, strlen('buildings/'))] ?? null);
        }

        // The index keeps each piece's size, and the animations that play when it's on, by facing drawn over.
        $index = $this->write($outputs, $animations, $avatar, array_map(
            fn (?array $prop, int $n): ?array => $prop === null ? null
                : ['slug' => $prop['slug'], 'wide' => $prop['wide'], 'deep' => $prop['deep'], ...(isset($running[$n]) ? ['animations' => $running[$n]] : [])],
            $props, array_keys($props),
        ));

        if ($maps) {
            $this->writeMaps(Png::encode($sheet), $sheet, $slots, $props, $pieces);
        }

        return $index;
    }

    /**
     * Every file under resources/art, relative to it, after checking each is
     * somewhere the build knows.
     *
     * @return list<string>
     */
    private function sourceFiles(string $sources): array
    {
        if (! is_dir($sources)) {
            throw new InvalidAssetException("There is no art folder at {$sources}.");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sources, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $info */
        foreach ($iterator as $info) {
            $file = str_replace('\\', '/', substr($info->getPathname(), strlen($sources) + 1));

            if ($info->getFilename() === '.gitkeep' || $info->getFilename() === '.DS_Store') {
                continue;
            }

            $png = preg_replace('/\.(aseprite|ase)$/', '.png', $file);
            $known = in_array($file, ['provenance.json', 'tiles/tiles.json', self::ANIMATIONS, self::RIG, self::PROP_REGISTRY], true)
                || preg_match('#^tiles/'.TileRegistry::NAME.'\.png$#', (string) $png)
                || preg_match('#^(ui|buildings)/'.self::SLUG.'\.png$#', (string) $png)
                || preg_match('#^buildings/'.self::SLUG.'/'.self::SLUG.'\.png$#', (string) $png)
                || preg_match('#^walls/'.self::SLUG.'/'.self::WALL_PART.'\.png$#', (string) $png)
                || preg_match('#^items/'.self::SLUG.'/[0-3]\.png$#', (string) $png)
                || preg_match('#^props/'.self::SLUG.'/[0-3](-on)?\.png$#', (string) $png)
                || preg_match('#^avatars/base/'.self::AVATAR_PART.'\.png$#', (string) $png)
                || preg_match('#^(avatars/hair|items)/'.self::SLUG.'/(front|back)(-behind)?\.png$#', (string) $png);

            if (! $known) {
                throw new InvalidAssetException("art/{$file} isn't a file the build knows. Names are lowercase words joined with hyphens, in the folders resources/maps/README.md lists.");
            }

            $files[] = $file;
        }

        sort($files);

        return $files;
    }

    /**
     * Every source must be claimed in provenance.json, by its path under
     * resources/ or a glob, so nothing ships that our artist didn't hand over.
     *
     * @param  list<string>  $files
     */
    private function checkProvenance(string $sources, array $files): void
    {
        $path = "{$sources}/provenance.json";
        $entries = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($entries)) {
            throw new InvalidAssetException('art/provenance.json must be a JSON object: who drew each file, and when it arrived.');
        }

        $unclaimed = array_filter(
            $files,
            fn (string $file): bool => ! in_array($file, ['provenance.json', 'tiles/tiles.json', self::ANIMATIONS, self::RIG, self::PROP_REGISTRY], true)
                && ! array_any(array_keys($entries), fn (int|string $pattern): bool => fnmatch((string) $pattern, "art/{$file}", FNM_PATHNAME)),
        );

        if ($unclaimed !== []) {
            throw new InvalidAssetException('No entry in art/provenance.json for: art/'.implode(', art/', $unclaimed).'.');
        }
    }

    /**
     * The ground tiles in slot order, COLUMNS to a row, each at game size. A
     * slot without a source is a placeholder block tinted by its type; a
     * retired slot is left empty.
     *
     * @param  list<string>  $files
     * @param  list<string|null>  $slots
     */
    private function tileSheet(string $sources, array $files, array $slots): Image
    {
        foreach ($files as $file) {
            if (preg_match('#^tiles/('.TileRegistry::NAME.')\.png$#', $file, $m) && ! in_array($m[1], $slots, true)) {
                throw new InvalidAssetException("art/{$file} has no slot. Add \"{$m[1]}\" to the end of tiles/tiles.json.");
            }
        }

        $rows = max(1, (int) ceil(count($slots) / self::COLUMNS));
        $sheet = Image::blank(self::COLUMNS * self::TILE, $rows * self::TILE);

        foreach ($slots as $n => $name) {
            if ($name === null) {
                continue;
            }

            $file = "tiles/{$name}.png";
            [$type, $height] = explode('/', $name);
            $half = str_starts_with($height, 'half');

            // Until a type has a full cube, its half slab stands in, sunk to the floor of the cube.
            if (! in_array($file, $files, true) && $height === 'full' && in_array("tiles/{$type}/half.png", $files, true)) {
                $this->warnings[] = "tiles/{$name} has no art yet: packing tiles/{$type}/half.png in its slot, so it reads sunken.";
                $file = "tiles/{$type}/half.png";
                $half = true;
            }

            $tile = in_array($file, $files, true)
                ? $this->checkSlab($file, $this->scaled($sources, $file, 'tiles', self::TILE, self::TILE), $half)
                : $this->placeholderTile($type, $half);
            $sheet = $sheet->paste($tile, ($n % self::COLUMNS) * self::TILE, intdiv($n, self::COLUMNS) * self::TILE);
        }

        return $sheet;
    }

    private function ui(string $sources, string $file, string $name): Image
    {
        if (! array_key_exists($name, self::UI)) {
            throw new InvalidAssetException("art/{$file} isn't a UI image the game uses: ".implode(', ', array_keys(self::UI)).'.');
        }

        [$width, $height] = self::UI[$name] ?? [null, null];

        return $this->scaled($sources, $file, 'ui', $width, $height);
    }

    /**
     * A building's art: as wide as its footprint, (wide + deep) × 32 game
     * pixels, standing on the footprint diamond at the bottom of the canvas.
     *
     * @param  array{int, int}|null  $footprint  wide and deep, from the maps
     */
    private function building(string $sources, string $file, ?array $footprint): Image
    {
        if ($footprint === null) {
            throw new InvalidAssetException("art/{$file} is for a building no map has. Its name must be a building's name in a map's \"buildings\" layer.");
        }

        [$wide, $deep] = $footprint;
        $art = $this->source($sources, $file, 'buildings', ($wide + $deep) * 32, null);
        $this->checkFootprint("art/{$file}", $art, $wide * intdiv(32, $this->scale('buildings')), "a {$wide} × {$deep} footprint");

        return $art->scale($this->scale('buildings'));
    }

    /**
     * How long each frame shows, in milliseconds, by animation ("whsh/beacon"),
     * from animations.json: `{"whsh/beacon": {"frame_ms": 200}}`, or a list
     * of times, one for each frame. Every animation needs an entry, and every
     * entry an animation.
     *
     * @param  list<string>  $files
     * @param  list<string>  $animated  each animation's output name, buildings/{slug}/{part}
     * @return array<string, mixed>
     */
    private function animationTimings(string $sources, array $files, array $animated): array
    {
        if (! in_array(self::ANIMATIONS, $files, true)) {
            return [];
        }

        $timings = json_decode((string) file_get_contents("{$sources}/".self::ANIMATIONS), true);

        if (! is_array($timings) || ($timings !== [] && array_is_list($timings))) {
            throw new InvalidAssetException('art/'.self::ANIMATIONS.' must be a JSON object: each animation\'s frame times, by building and part, such as {"whsh/beacon": {"frame_ms": 200}}.');
        }

        foreach (array_keys($timings) as $key) {
            if (! in_array("buildings/{$key}", $animated, true)) {
                throw new InvalidAssetException('art/'.self::ANIMATIONS." times \"{$key}\", but there is no art/buildings/{$key}.png.");
            }
        }

        return $timings;
    }

    /**
     * A building animation: a strip of frames, each the building's whole
     * canvas, drawn over it (see overlay).
     *
     * @param  list<string>  $files
     * @param  array{int, int}|null  $footprint  the building's wide and deep, from the maps
     * @return array{Image, array{x: int, y: int, width: int, height: int, frames: int, frame_ms: list<int>}}
     */
    private function animation(string $sources, string $file, string $slug, array $files, ?array $footprint, mixed $timing): array
    {
        if ($footprint === null || ! in_array("buildings/{$slug}.png", $files, true)) {
            throw new InvalidAssetException("art/{$file} is drawn over art/buildings/{$slug}.png, which isn't there. Its folder must be named exactly like the building's own art.");
        }

        $scale = $this->scale('buildings');
        $building = Png::decode((string) file_get_contents("{$sources}/buildings/{$slug}.png"), "art/buildings/{$slug}.png");

        return $this->overlay(
            Png::decode((string) file_get_contents("{$sources}/{$file}"), "art/{$file}"), "art/{$file}", $building, "art/buildings/{$slug}.png", $scale,
            $footprint[0] * intdiv(32, $scale), "a {$footprint[0]} × {$footprint[1]} footprint", $timing,
            fn (int $count): string => 'art/'.self::ANIMATIONS.': "'.substr($file, strlen('buildings/'), -4)."\": {\"frame_ms\": 200} shows every frame for 200 ms, or give a list of {$count} times, one for each frame.",
        );
    }

    /**
     * Frames drawn over a picture, `base`: a strip of frames, each the
     * picture's whole canvas, side by side. Checked frame by frame as the
     * picture is (nothing below the footprint diamond whose bottom corner is
     * `corner` in), then trimmed to the box around every pixel any frame
     * draws, so the game loads only what moves, and where that box lies on
     * the picture. `timing` holds `frame_ms`, one time for every frame or a
     * list; `timesGo` says where they go, for a strip of so many frames.
     * `name` and `baseName` are how messages refer to the strip and picture.
     *
     * @param  Closure(int): string  $timesGo
     * @return array{Image, array{x: int, y: int, width: int, height: int, frames: int, frame_ms: list<int>}}
     */
    private function overlay(Image $strip, string $name, Image $base, string $baseName, int $scale, int $corner, string $what, mixed $timing, Closure $timesGo): array
    {
        [$w, $h] = [$base->width, $base->height];

        if ($scale > 1 && $strip->height === $h * $scale && $strip->width % ($w * $scale) === 0 && $strip->isScaledUp($scale)) {
            throw new InvalidAssetException("{$name} is already scaled up to {$strip->width}×{$strip->height}. Export it at 100%: ".intdiv($strip->width, $scale)."×{$h}.");
        }

        if ($strip->height !== $h || $strip->width % $w !== 0) {
            throw new InvalidAssetException("{$name} is {$strip->width}×{$strip->height}; its frames must be {$w} × {$h}, like {$baseName}, side by side.");
        }

        $count = intdiv($strip->width, $w);

        if ($count < 2) {
            throw new InvalidAssetException("{$name} has one frame. An animation needs two or more; a part that doesn't move belongs in {$baseName}.");
        }

        $times = match (true) {
            is_array($timing) && is_int($timing['frame_ms'] ?? null) && $timing['frame_ms'] > 0 => array_fill(0, $count, $timing['frame_ms']),
            is_array($timing) && is_array($timing['frame_ms'] ?? null) && array_is_list($timing['frame_ms']) && count($timing['frame_ms']) === $count
                && array_all($timing['frame_ms'], fn (mixed $ms): bool => is_int($ms) && $ms > 0) => $timing['frame_ms'],
            default => throw new InvalidAssetException("{$name} needs its frame times in {$timesGo($count)}"),
        };

        [$minX, $minY, $maxX, $maxY] = [$w, $h, -1, -1];
        $frames = [];

        for ($n = 0; $n < $count; $n++) {
            $frame = $strip->crop($n * $w, 0, $w, $h);
            $this->checkFootprint('Frame '.($n + 1)." of {$name}", $frame, $corner, $what);

            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $alpha = $frame->alphaAt($x, $y);

                    if ($alpha > 0 && $alpha < 255) {
                        throw new InvalidAssetException('Frame '.($n + 1)." of {$name} has a half-transparent pixel at ({$x}, {$y}). Use solid pixels.");
                    }

                    if ($alpha > 0) {
                        [$minX, $minY, $maxX, $maxY] = [min($minX, $x), min($minY, $y), max($maxX, $x), max($maxY, $y)];
                    }
                }
            }

            $frames[] = $frame;
        }

        if ($maxX < 0) {
            throw new InvalidAssetException("{$name} has nothing drawn in it.");
        }

        [$width, $height] = [$maxX - $minX + 1, $maxY - $minY + 1];
        $out = Image::blank($width * $count, $height);

        foreach ($frames as $n => $frame) {
            $out = $out->paste($frame->crop($minX, $minY, $width, $height), $n * $width, 0);
        }

        return [$out->scale($scale), [
            'x' => $minX * $scale,
            'y' => $minY * $scale,
            'width' => $width * $scale,
            'height' => $height * $scale,
            'frames' => $count,
            'frame_ms' => $times,
        ]];
    }

    /**
     * The avatar: checks every base, hair and wearable strip against the rig,
     * adds each to `outputs` at game size, and returns what the game needs to
     * play them: the rig in game pixels, with the anchors read from the base's
     * anchor strips, frame by frame. The anchors aren't shipped as images.
     *
     * @param  list<string>  $files
     * @param  list<string>  $dressing  every avatar and wearable strip but the anchors
     * @param  array<string, Image>  $outputs
     * @return array<string, mixed>
     */
    private function avatar(string $sources, array $files, array $dressing, array &$outputs): array
    {
        $rig = in_array(self::RIG, $files, true) ? json_decode((string) file_get_contents("{$sources}/".self::RIG), true) : null;
        $valid = is_array($rig) && is_array($rig['frame'] ?? null) && is_int($rig['frame'][0] ?? null) && is_int($rig['frame'][1] ?? null)
            && is_array($rig['animations'] ?? null) && is_array($rig['anchors'] ?? null) && is_array($rig['slots'] ?? null) && is_array($rig['layers'] ?? null);

        if (! $valid) {
            throw new InvalidAssetException('Avatar art needs art/'.self::RIG.': its frame size, animations, anchors, slots and layers (docs/assets-plan.md §14).');
        }

        $scale = $this->scale('avatars');
        [$w, $h] = $rig['frame'];
        $count = 1 + max(0, ...array_merge(...array_map(fn (array $a): array => $a['frames'] ?? [0], array_values($rig['animations']))));
        $anchors = [];

        foreach (['front', 'back'] as $facing) {
            foreach (["avatars/base/{$facing}.png", "avatars/base/{$facing}-anchors.png"] as $needed) {
                if (! in_array($needed, $files, true)) {
                    throw new InvalidAssetException("Avatar art needs art/{$needed}: the base body every resident wears, {$count} frames of {$w} × {$h} side by side.");
                }
            }

            $anchors[$facing] = $this->anchors($this->strip($sources, "avatars/base/{$facing}-anchors.png", $w, $h, [$count], $scale), $w, $h, $count, $rig['anchors'], $scale);
        }

        foreach ($dressing as $file) {
            // The base and drawn wearables have every frame; hair and rigid wearables one, moved to its anchor.
            $frames = str_starts_with($file, 'avatars/base/') ? [$count] : (str_starts_with($file, 'avatars/hair/') ? [1] : [1, $count]);
            $outputs[substr($file, 0, -4)] = $this->strip($sources, $file, $w, $h, $frames, $scale)->scale($scale);
        }

        return [
            'frame' => [$w * $scale, $h * $scale],
            'frames' => $count,
            'animations' => $rig['animations'],
            'stride_px' => $rig['stride_px'] ?? 64,
            'slots' => $rig['slots'],
            'layers' => $rig['layers'],
            'anchors' => $anchors,
        ];
    }

    /**
     * A strip of frames `w` × `h` art pixels, side by side, as many as one of
     * `frames` allows, in solid pixels.
     *
     * @param  list<int>  $frames
     */
    private function strip(string $sources, string $file, int $w, int $h, array $frames, int $scale): Image
    {
        $strip = Png::decode((string) file_get_contents("{$sources}/{$file}"), "art/{$file}");

        if ($scale > 1 && $strip->height === $h * $scale && in_array(intdiv($strip->width, $w * $scale), $frames, true) && $strip->isScaledUp($scale)) {
            throw new InvalidAssetException("art/{$file} is already scaled up to {$strip->width}×{$strip->height}. Export it at 100%: ".intdiv($strip->width, $scale)."×{$h}.");
        }

        if ($strip->height !== $h || $strip->width % $w !== 0 || ! in_array(intdiv($strip->width, $w), $frames, true)) {
            $what = implode(' or ', array_map(fn (int $n): string => $n === 1 ? "one frame, {$w} × {$h}" : "{$n} frames of {$w} × {$h} side by side", $frames));

            throw new InvalidAssetException("art/{$file} is {$strip->width}×{$strip->height}; it must be {$what}.");
        }

        for ($y = 0; $y < $strip->height; $y++) {
            for ($x = 0; $x < $strip->width; $x++) {
                $alpha = $strip->alphaAt($x, $y);

                if ($alpha > 0 && $alpha < 255) {
                    throw new InvalidAssetException('Frame '.(intdiv($x, $w) + 1)." of art/{$file} has a half-transparent pixel at (".($x % $w).", {$y}). Use solid pixels.");
                }
            }
        }

        return $strip;
    }

    /**
     * Where each anchor is in each frame, in game pixels: the one pixel of its
     * colour in that frame of the anchors' strip.
     *
     * @param  array<string, string>  $colours  anchor => hex
     * @return list<array<string, array{int, int}>>
     */
    private function anchors(Image $strip, int $w, int $h, int $count, array $colours, int $scale): array
    {
        $frames = [];

        foreach (range(0, $count - 1) as $n) {
            $found = [];

            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $at = (($y * $strip->width) + $n * $w + $x) * 4;

                    if (ord($strip->pixels[$at + 3]) === 0) {
                        continue;
                    }

                    $name = array_search(bin2hex(substr($strip->pixels, $at, 3)), $colours, true);

                    if ($name === false || isset($found[$name])) {
                        throw new InvalidAssetException('Frame '.($n + 1)." of the avatar's anchors has ".($name === false ? 'a pixel that marks no anchor' : "two {$name} anchors")." at ({$x}, {$y}). Mark each anchor with one pixel of its colour: ".implode(', ', array_map(fn (string $a, string $c): string => "{$a} #{$c}", array_keys($colours), $colours)).'.');
                    }

                    $found[(string) $name] = [$x * $scale, $y * $scale];
                }
            }

            foreach (array_keys($colours) as $name) {
                if (! isset($found[$name])) {
                    throw new InvalidAssetException('Frame '.($n + 1)." of the avatar's anchors has no {$name} anchor.");
                }
            }

            $frames[] = $found;
        }

        return $frames;
    }

    /**
     * A furniture facing. Its size isn't known until the game draws it (it
     * comes from the catalog, which the build can't read), so the build checks
     * the canvas is a whole number of tiles across and that nothing hangs below
     * where a footprint diamond could lie; the game draws a placeholder for
     * art whose width doesn't match the piece, and the item list shows it.
     */
    private function furniture(string $sources, string $file): Image
    {
        $scale = $this->scale('items');
        $art = $this->source($sources, $file, 'items', null, null);
        $tile = intdiv(32, $scale);

        if ($art->width % $tile !== 0 || $art->width < 2 * $tile) {
            throw new InvalidAssetException("art/{$file} is {$art->width} wide; it must be (wide + deep) × {$tile}: {$tile} for each tile the piece runs along each way, so ".(2 * $tile).' for a 1 × 1 piece.');
        }

        // Fine if it stands on the footprint of any piece this wide, whichever way it's turned.
        $first = null;

        for ($wide = 1; $wide < intdiv($art->width, $tile); $wide++) {
            $problem = $this->footprintProblem($art, $wide * $tile, "art/{$file}");

            if ($problem === null) {
                return $art->scale($scale);
            }

            $first ??= $problem;
        }

        throw new InvalidAssetException($first ?? "art/{$file} hangs below its footprint.");
    }

    /**
     * Room pieces: each facing drawn in art/props/{slug}/, checked against
     * the piece's size in props.json as uploaded furniture is, and doubled
     * into `outputs`, with the animation drawn over each facing for a piece
     * that switches on ({facing}-on.png, see overlay). Returns each piece's
     * pictures for the props tileset, at every facing and, for a piece that
     * switches on, switched on (index 4 + facing) with its first frame drawn
     * in: the facings not drawn standing in as the game draws them
     * (facingArt in resources/js/game/shared/furniture.ts), padded so the
     * footprint's bottom corner is the middle of the bottom edge, where Tiled
     * pins a tile placed on an isometric map. And each animation, by slot
     * and the facing it is drawn over.
     *
     * @param  list<string>  $files
     * @param  list<array{slug: string, wide: int, deep: int, frame_ms?: int|list<int>}|null>  $props
     * @param  array<string, Image>  $outputs
     * @return array{array<int, array<int, Image>>, array<int, array<int, array{x: int, y: int, width: int, height: int, frames: int, frame_ms: list<int>}>>} slot => tile => picture, in game pixels; slot => facing => animation
     */
    private function props(string $sources, array $files, array $props, array &$outputs): array
    {
        $listed = array_column(array_filter($props), 'slug');

        foreach ($files as $file) {
            if (preg_match('#^props/('.self::SLUG.')/[0-3](-on)?\.png$#', $file, $m) && ! in_array($m[1], $listed, true)) {
                throw new InvalidAssetException("art/{$file} is for a room piece props/props.json doesn't list. Add {\"slug\": \"{$m[1]}\", \"wide\": 1, \"deep\": 1} to the end of it, with the tiles it covers.");
            }
        }

        $scale = $this->scale('props');
        $pieces = [];
        $animations = [];

        foreach ($props as $n => $prop) {
            if ($prop === null) {
                continue;
            }

            $drawn = [];
            $art = [];
            $running = [];

            foreach (range(0, 3) as $facing) {
                $file = "props/{$prop['slug']}/{$facing}.png";
                $on = "props/{$prop['slug']}/{$facing}-on.png";
                [$wide, $deep] = PropRegistry::lies($prop, $facing);

                if (in_array($file, $files, true)) {
                    $art[$facing] = $this->source($sources, $file, 'props', ($wide + $deep) * 32, null);
                    $this->checkFootprint("art/{$file}", $art[$facing], $wide * intdiv(32, $scale), "a {$wide} × {$deep} footprint at facing {$facing}");
                    $drawn[$facing] = $outputs["props/{$prop['slug']}/{$facing}"] = $art[$facing]->scale($scale);
                }

                if (in_array($on, $files, true)) {
                    if (! isset($art[$facing], $drawn[$facing])) {
                        throw new InvalidAssetException("art/{$on} is drawn over art/{$file}, which isn't there. Draw the piece switched off at that facing first.");
                    }

                    [$outputs["props/{$prop['slug']}/{$facing}-on"], $animations[$n][$facing]] = $this->overlay(
                        Png::decode((string) file_get_contents("{$sources}/{$on}"), "art/{$on}"), "art/{$on}", $art[$facing], "art/{$file}", $scale, $wide * intdiv(32, $scale), "a {$wide} × {$deep} footprint at facing {$facing}", $prop,
                        fn (int $count): string => "props/props.json: add \"frame_ms\": 200 to {$prop['slug']} to show every frame for 200 ms, or give a list of {$count} times, one for each frame.",
                    );
                    $strip = $outputs["props/{$prop['slug']}/{$facing}-on"];
                    $at = $animations[$n][$facing];
                    $running[$facing] = $drawn[$facing]->over($strip->crop(0, 0, $at['width'], $at['height']), $at['x'], $at['y']);
                }
            }

            if ($drawn === []) {
                throw new InvalidAssetException("props/props.json lists {$prop['slug']}, but it has no art. Draw art/props/{$prop['slug']}/0.png, its front.");
            }

            if (isset($prop['frame_ms']) && $running === []) {
                throw new InvalidAssetException("props/props.json gives {$prop['slug']} frame times, but it has no animation. Draw art/props/{$prop['slug']}/0-on.png, the frames that play over its front when it's on.");
            }

            foreach (range(0, 3) as $facing) {
                // A plain block fits its mirror image (facing 3 − f) or the opposite facing,
                // so any one drawn facing does for all four; a facing of the other parity is mirrored.
                $opposite = ($facing + 2) % 4;

                foreach ([$facing, 3 - $facing, $opposite, 3 - $opposite] as $from) {
                    if (! isset($drawn[$from])) {
                        continue;
                    }

                    $corner = PropRegistry::lies($prop, $facing)[0] * 32;
                    // Switched on, it shows the animation drawn over the same picture, or stays still if there is none.
                    $states = $running === [] ? [0 => $drawn[$from]] : [0 => $drawn[$from], 4 => $running[$from] ?? $drawn[$from]];

                    foreach ($states as $state => $shown) {
                        $picture = ($from + $facing) % 2 === 1 ? $shown->mirrored() : $shown;
                        $half = max($corner, $picture->width - $corner);
                        $pieces[$n][$state + $facing] = Image::blank(2 * $half, $picture->height)->paste($picture, $half - $corner, 0);
                    }

                    break;
                }
            }

            // In Tiled's tileset panel, the four facings off, then on.
            ksort($pieces[$n]);
        }

        return [$pieces, $animations];
    }

    /**
     * A wall panel: one tile edge across (32 game pixels) and at least 112
     * tall, standing on the floor edge that climbs 16 game pixels across its
     * bottom: up to the right on a left panel, down to the right on a right one.
     */
    private function wall(string $sources, string $file, bool $left): Image
    {
        $scale = $this->scale('walls');
        $art = $this->source($sources, $file, 'walls', 32, null);
        $climb = intdiv(16, $scale);

        if ($art->height < intdiv(112, $scale)) {
            throw new InvalidAssetException("art/{$file} is {$art->height} tall; a wall panel must be at least ".intdiv(112, $scale).' tall.');
        }

        for ($x = 0; $x < $art->width; $x++) {
            // The floor edge at this column's middle, in rows from the top.
            $edge = $art->height - ($left ? ($x + 0.5) : ($art->width - $x - 0.5)) * $climb / $art->width;

            for ($y = (int) floor($edge) + 1; $y < $art->height; $y++) {
                if ($art->alphaAt($x, $y) > 0) {
                    throw new InvalidAssetException("art/{$file} has pixels below the floor edge at ({$x}, {$y}). The edge runs from the bottom-".($left ? 'left corner up to '.$climb.' above the bottom-right' : 'right corner up to '.$climb.' above the bottom-left').'.');
                }
            }
        }

        return $art->scale($scale);
    }

    /**
     * A furniture facing uploaded in the admin catalog (docs/assets-plan.md
     * §8): checked as the build checks repo art, but against the piece's own
     * size, `wide` × `deep` as it lies at this facing, and multiplied up to
     * game size. `name` is how messages refer to the file.
     */
    public function furnitureFacing(string $bytes, string $name, int $wide, int $deep): Image
    {
        $scale = $this->scale('items');
        $art = $this->checked(Png::decode($bytes, $name), $name, $scale, ($wide + $deep) * 32, null);
        $this->checkFootprint($name, $art, $wide * intdiv(32, $scale), "a {$wide} × {$deep} footprint");

        return $art->scale($scale);
    }

    /**
     * The animation uploaded with a furniture facing, to play over its
     * picture (`picture`, in game pixels, as furnitureFacing returns it)
     * while the piece is switched on: checked and trimmed as a room piece's
     * is (see overlay), at most MAX_UPLOAD_STRIP_WIDTH wide once trimmed,
     * each frame shown at least MIN_FRAME_MS. `name` is how messages refer
     * to the file.
     *
     * @param  list<int>  $frameMs  one time for every frame, or one for each
     * @return array{Image, array{x: int, y: int, width: int, height: int, frames: int, frame_ms: list<int>}}
     */
    public function furnitureAnimation(string $bytes, string $name, Image $picture, int $wide, int $deep, array $frameMs): array
    {
        $scale = $this->scale('items');
        $base = $picture->sample($scale);

        if (array_any($frameMs, fn (int $ms): bool => $ms < self::MIN_FRAME_MS)) {
            throw new InvalidAssetException("{$name} shows a frame for less than ".self::MIN_FRAME_MS.' ms. Anything quicker flickers.');
        }

        $animation = $this->overlay(
            Png::decode($bytes, $name), $name, $base, 'its picture', $scale, $wide * intdiv(32, $scale), "a {$wide} × {$deep} footprint",
            ['frame_ms' => count($frameMs) === 1 ? $frameMs[0] : $frameMs],
            fn (int $count): string => "\"Frame times\": one time for every frame, or {$count} times, one for each frame.",
        );
        ['width' => $width, 'frames' => $frames] = $animation[1];

        if ($width * $frames > self::MAX_UPLOAD_STRIP_WIDTH) {
            $most = intdiv(self::MAX_UPLOAD_STRIP_WIDTH, $width);

            throw new InvalidAssetException("{$name} is too long to load: {$frames} frames of what moves, each ".intdiv($width, $scale)." art pixels across, fit only {$most} at a time. Use up to {$most} frames, or make the moving part narrower.");
        }

        return $animation;
    }

    /** Fails unless nothing hangs below the footprint diamond whose bottom corner is `corner` pixels in. */
    private function checkFootprint(string $name, Image $art, int $corner, string $what): void
    {
        $problem = $this->footprintProblem($art, $corner, $name);

        if ($problem !== null) {
            throw new InvalidAssetException("{$problem} It stands on {$what}.");
        }
    }

    /**
     * What hangs below a footprint diamond whose bottom corner touches the
     * bottom of the canvas `corner` pixels from its left edge, the diamond's
     * edges climbing one pixel for every two across, or null if nothing.
     * Hand-drawn edges may stray by a pixel.
     */
    private function footprintProblem(Image $art, int $corner, string $name): ?string
    {
        $rows = intdiv($art->width, 2) + 1;

        for ($y = max(0, $art->height - $rows); $y < $art->height; $y++) {
            for ($x = 0; $x < $art->width; $x++) {
                $edge = $art->height - abs($x + 0.5 - $corner) / 2;

                if ($y > $edge + 1 && $art->alphaAt($x, $y) > 0) {
                    return "{$name} has pixels below its footprint diamond at ({$x}, {$y}).";
                }
            }
        }

        return null;
    }

    /**
     * Each building's footprint, wide and deep, from the "buildings" layer of
     * every map. One building's name stands for one piece of art, so it must
     * have the same footprint wherever it stands.
     *
     * @return array<string, array{int, int}>
     */
    private function buildingFootprints(): array
    {
        $found = [];

        foreach (glob(config('hushfield.maps.path').'/*.json') ?: [] as $path) {
            $map = json_decode((string) file_get_contents($path), true);
            $unit = is_array($map) && is_int($map['tileheight'] ?? null) && $map['tileheight'] > 0 ? $map['tileheight'] : 32;
            $layers = is_array($map) && is_array($map['layers'] ?? null) ? $map['layers'] : [];

            while ($layers !== []) {
                $layer = array_shift($layers);

                if (! is_array($layer)) {
                    continue;
                }

                array_push($layers, ...(is_array($layer['layers'] ?? null) ? $layer['layers'] : []));

                if (($layer['name'] ?? null) !== 'buildings' || ! is_array($layer['objects'] ?? null)) {
                    continue;
                }

                foreach ($layer['objects'] as $object) {
                    if (! is_array($object) || ! is_string($object['name'] ?? null) || ! is_numeric($object['width'] ?? null) || ! is_numeric($object['height'] ?? null)) {
                        continue;
                    }

                    $size = [(int) round($object['width'] / $unit), (int) round($object['height'] / $unit)];
                    $seen = $found[$object['name']] ?? $size;

                    if ($seen !== $size) {
                        throw new InvalidAssetException("The building {$object['name']} is {$seen[0]} × {$seen[1]} in one map and {$size[0]} × {$size[1]} in another; one name has one piece of art, so it needs one size.");
                    }

                    $found[$object['name']] = $size;
                }
            }
        }

        return $found;
    }

    /** A source checked against its size in game pixels (null: any), multiplied up to game size. */
    private function scaled(string $sources, string $file, string $kind, ?int $width, ?int $height): Image
    {
        return $this->source($sources, $file, $kind, $width, $height)->scale($this->scale($kind));
    }

    /**
     * A source in art pixels, checked against its size in game pixels (null:
     * any) divided by its kind's scale, and refused if it was exported
     * already scaled up.
     */
    private function source(string $sources, string $file, string $kind, ?int $width, ?int $height): Image
    {
        return $this->checked(Png::decode((string) file_get_contents("{$sources}/{$file}"), "art/{$file}"), "art/{$file}", $this->scale($kind), $width, $height);
    }

    /**
     * An image in art pixels, checked against its size in game pixels (null:
     * any) divided by `scale`, and refused if it was exported already scaled up.
     */
    private function checked(Image $image, string $name, int $scale, ?int $width, ?int $height): Image
    {
        $wantWidth = $width === null ? null : intdiv($width, $scale);
        $wantHeight = $height === null ? null : intdiv($height, $scale);
        $matches = fn (int $factor): bool => ($wantWidth === null || $image->width === $wantWidth * $factor)
            && ($wantHeight === null || $image->height === $wantHeight * $factor);

        if ($scale > 1 && $matches($scale) && $image->isScaledUp($scale)) {
            $expected = ($wantWidth ?? intdiv($image->width, $scale)).'×'.($wantHeight ?? intdiv($image->height, $scale));

            throw new InvalidAssetException("{$name} is already scaled up to {$image->width}×{$image->height}. Export it at 100%: {$expected}.");
        }

        if (! $matches(1)) {
            $size = $wantHeight === null ? "{$wantWidth} wide" : ($wantWidth === null ? "{$wantHeight} tall" : "{$wantWidth}×{$wantHeight}");

            throw new InvalidAssetException("{$name} is {$image->width}×{$image->height}; it must be {$size}.");
        }

        return $image;
    }

    /** How many game pixels one art pixel of this kind covers each way. */
    private function scale(string $kind): int
    {
        $scale = config("hushfield.assets.scale_overrides.{$kind}") ?? config('hushfield.assets.scale');

        if (! is_int($scale) || $scale < 1 || self::TILE % $scale !== 0) {
            throw new InvalidAssetException("The art scale for {$kind} must be a whole number that divides ".self::TILE.'.');
        }

        return $scale;
    }

    /**
     * A stand-in for a tile with no art yet: the block shape her tiles share,
     * drawn on the 32 × 32 grid of a half-size tile and doubled, its top face
     * tinted by the tile's type and its sides shaded as light from the top
     * left falls. A full cube's top face is on the canvas's top rows; a half
     * slab's sits 8 art pixels lower.
     */
    private function placeholderTile(string $type, bool $half): Image
    {
        $colour = $this->tint($type);
        $top = self::SLAB_TOP[$half ? 'half' : 'full'];
        $pixels = '';

        for ($y = 0; $y < 32; $y++) {
            // Half the block's width on this row, and of its 32 × 16 top face from row $top.
            $block = $y < $top ? 0 : ($y <= $top + 7 ? 2 * ($y - $top + 1) : ($y <= 24 ? 16 : 16 - 2 * ($y - 24)));
            $face = $y < $top ? 0 : ($y <= $top + 7 ? 2 * ($y - $top + 1) : max(0, 16 - 2 * ($y - $top - 8)));

            for ($x = 0; $x < 32; $x++) {
                $from = abs($x - 15.5) + 0.5;
                $shade = $from > $block ? null : ($from <= $face ? 1.0 : ($x < 16 ? 0.8 : 0.65));
                $pixels .= $shade === null
                    ? "\0\0\0\0"
                    : pack('C4', ...[...array_map(fn (int $c): int => (int) ($c * $shade), $colour), 255]);
            }
        }

        return (new Image(32, 32, $pixels))->scale(2);
    }

    /**
     * Checks a ground tile has the block shape of its height, in art pixels on
     * its 32 × 32 canvas: bottom-aligned, so nothing hangs below the block's
     * bottom edge (the V from row 24 at the sides to row 31 in the middle), and
     * its sides reach that edge. A full cube's sides stand 16 tall at the
     * canvas's left and right edges (rows 8 to 24), its top face on rows 0 to
     * 15; a half slab's sides stand 8 tall, its top face 8 lower. Tufts may
     * poke above the top face, and hand-drawn edges stray by a pixel, so each
     * test allows a little.
     */
    private function checkSlab(string $file, Image $tile, bool $half): Image
    {
        // Back to art pixels: every block of the game-size tile is one art pixel.
        $scale = intdiv($tile->width, 32);
        $opaque = fn (int $x, int $y): bool => $tile->alphaAt($x * $scale, $y * $scale) > 0;
        $edge = fn (int $y): bool => $opaque(0, $y) || $opaque(1, $y) || $opaque(30, $y) || $opaque(31, $y);
        $rows = fn (int $from, int $to): int => count(array_filter(range($from, $to), $edge));

        for ($y = 25; $y < 32; $y++) {
            for ($x = 0; $x < 32; $x++) {
                if ($opaque($x, $y) && abs($x - 15.5) > 16 - 2 * ($y - 24) + 1) {
                    throw new InvalidAssetException("art/{$file} has pixels below the block's bottom edge at ({$x}, {$y}). Blocks stand on the bottom of the canvas.");
                }
            }
        }

        if ($rows(21, 24) < 3) {
            throw new InvalidAssetException("art/{$file}'s sides don't reach the bottom of the canvas. Blocks stand on the bottom edge, rows 24 to 31.");
        }

        if (! $half && $rows(9, 12) < 3) {
            throw new InvalidAssetException("art/{$file} is too short for a full cube: its sides should stand 16 pixels tall at the canvas's edges, its top face on rows 0 to 15. Is it a half slab?");
        }

        if ($half && $rows(9, 12) > 1) {
            throw new InvalidAssetException("art/{$file} is too tall for a half slab: its sides should stand 8 pixels tall, its top face 8 pixels lower than a full cube's.");
        }

        return $tile;
    }

    /**
     * A stable colour per slug, as the client tints placeholders (colourFor).
     *
     * @return array{int, int, int}
     */
    private function tint(string $slug): array
    {
        $hash = 0;

        foreach (str_split($slug) as $char) {
            $hash = ($hash * 31 + ord($char)) % 4294967296;
        }

        // Hue from the slug; saturation 0.35 and lightness 0.55, as for furniture.
        $h = ($hash % 360) / 360;
        $q = 0.55 + 0.35 - 0.55 * 0.35;
        $p = 2 * 0.55 - $q;
        $channel = function (float $t) use ($p, $q): int {
            $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);
            $v = $t < 1 / 6 ? $p + ($q - $p) * 6 * $t : ($t < 1 / 2 ? $q : ($t < 2 / 3 ? $p + ($q - $p) * (2 / 3 - $t) * 6 : $p));

            return (int) round($v * 255);
        };

        return [$channel($h + 1 / 3), $channel($h), $channel($h - 1 / 3)];
    }

    /**
     * Writes each output under its content hash and the index naming them,
     * then deletes outputs no longer listed.
     *
     * @param  array<string, Image>  $outputs
     * @param  array<string, array{x: int, y: int, width: int, height: int, frames: int, frame_ms: list<int>}>  $animations
     * @param  array<string, mixed>|null  $avatar
     * @param  list<array{slug: string, wide: int, deep: int, animations?: array<int, array{x: int, y: int, width: int, height: int, frames: int, frame_ms: list<int>}>}|null>  $props
     * @return array{version: string, files: array<string, string>, sizes: array<string, array{int, int}>, animations?: array<string, array{x: int, y: int, width: int, height: int, frames: int, frame_ms: list<int>}>, avatar?: array<string, mixed>, props?: list<array{slug: string, wide: int, deep: int, animations?: array<int, array{x: int, y: int, width: int, height: int, frames: int, frame_ms: list<int>}>}|null>}
     */
    private function write(array $outputs, array $animations = [], ?array $avatar = null, array $props = []): array
    {
        $dir = (string) config('hushfield.assets.output');
        $files = [];
        $sizes = [];

        foreach ($outputs as $name => $image) {
            $bytes = Png::encode($image);
            $file = $name.'.'.substr(hash('sha256', $bytes), 0, 8).'.png';
            $files[$name] = $file;
            $sizes[$name] = [$image->width, $image->height];

            if (! is_file("{$dir}/{$file}")) {
                File::ensureDirectoryExists(dirname("{$dir}/{$file}"));
                $this->put("{$dir}/{$file}", $bytes);
            }
        }

        ksort($files);
        ksort($sizes);
        ksort($animations);
        // Frame times aren't in any file's hash, so they count towards the version too.
        // So do the avatar's rig and the room pieces' sizes.
        $versioned = $animations === [] && $avatar === null && $props === [] ? $files : [$files, $animations, $avatar, ...($props === [] ? [] : [$props])];
        $index = ['version' => substr(hash('sha256', (string) json_encode($versioned)), 0, 16), 'files' => $files, 'sizes' => $sizes];

        if ($animations !== []) {
            $index['animations'] = $animations;
        }

        if ($avatar !== null) {
            $index['avatar'] = $avatar;
        }

        if ($props !== []) {
            $index['props'] = $props;
        }

        $path = (string) config('hushfield.assets.index');
        File::ensureDirectoryExists(dirname($path));
        $this->put($path, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        foreach (File::allFiles($dir) as $info) {
            if (! in_array(str_replace('\\', '/', $info->getRelativePathname()), $files, true)) {
                File::delete($info->getPathname());
            }
        }

        return $index;
    }

    /**
     * The ground.png preview for Tiled, and the tileset block in every map:
     * the sheet's layout and a slug on each tile, so a map always matches the
     * sheet the game draws. Maps with a "props" layer also get the props
     * tileset, a picture of every room piece at every facing, and switched
     * on for a piece that switches on, from previews in props/ beside them.
     *
     * @param  list<string|null>  $slots
     * @param  list<array{slug: string, wide: int, deep: int, frame_ms?: int|list<int>}|null>  $props
     * @param  array<int, array<int, Image>>  $pieces  slot => tile (4 × on + facing) => picture
     */
    private function writeMaps(string $png, Image $sheet, array $slots, array $props, array $pieces): void
    {
        $maps = (string) config('hushfield.maps.path');

        if (! is_file("{$maps}/ground.png") || file_get_contents("{$maps}/ground.png") !== $png) {
            $this->put("{$maps}/ground.png", $png);
        }

        $previews = [];
        $propTiles = [];

        foreach ($pieces as $n => $tiles) {
            foreach ($tiles as $tile => $picture) {
                [$facing, $on] = [$tile % 4, $tile >= 4];
                $image = "props/{$props[$n]['slug']}-{$facing}".($on ? '-on' : '').'.png';
                $previews[$image] = Png::encode($picture);
                $propTiles[] = [
                    'id' => $n * PropRegistry::TILES + $tile,
                    'image' => $image,
                    'imagewidth' => $picture->width,
                    'imageheight' => $picture->height,
                    'properties' => [
                        ['name' => 'piece', 'type' => 'string', 'value' => $props[$n]['slug']],
                        ['name' => 'facing', 'type' => 'int', 'value' => $facing],
                        ['name' => 'on', 'type' => 'bool', 'value' => $on],
                    ],
                ];
            }
        }

        foreach ($previews as $image => $bytes) {
            if (! is_file("{$maps}/{$image}") || file_get_contents("{$maps}/{$image}") !== $bytes) {
                File::ensureDirectoryExists("{$maps}/props");
                $this->put("{$maps}/{$image}", $bytes);
            }
        }

        foreach (is_dir("{$maps}/props") ? File::files("{$maps}/props") : [] as $info) {
            if (! isset($previews['props/'.$info->getFilename()])) {
                File::delete($info->getPathname());
            }
        }

        $tiles = [];

        foreach ($slots as $n => $name) {
            if ($name !== null) {
                $tiles[] = ['id' => $n, 'properties' => [['name' => 'slug', 'type' => 'string', 'value' => $name]]];
            }
        }

        foreach (glob("{$maps}/*.json") ?: [] as $path) {
            $contents = (string) file_get_contents($path);
            $map = json_decode($contents);

            if (! is_object($map) || ! is_array($map->tilesets ?? null)) {
                continue;
            }

            foreach ($map->tilesets as $i => $tileset) {
                if (is_object($tileset) && ($tileset->name ?? null) === self::TILESET) {
                    $map->tilesets[$i] = [
                        'firstgid' => $tileset->firstgid ?? 1,
                        'name' => self::TILESET,
                        'image' => 'ground.png',
                        'imagewidth' => $sheet->width,
                        'imageheight' => $sheet->height,
                        'tilewidth' => self::TILE,
                        'tileheight' => self::TILE,
                        'columns' => self::COLUMNS,
                        'tilecount' => count($slots),
                        'margin' => 0,
                        'spacing' => 0,
                        'tileoffset' => ['x' => 0, 'y' => self::TILED_OFFSET_Y],
                        'tiles' => $tiles,
                    ];
                }
            }

            $layers = is_array($map->layers ?? null) ? $map->layers : [];

            if ($propTiles !== [] && $this->hasLayer($layers, self::PROPS)) {
                $map->tilesets = $this->withPropTileset($map->tilesets, $layers, $propTiles, count($slots));
            }

            // Tiled's compact export, byte for byte, so only the tileset shows in a diff.
            $written = (string) json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

            if ($written !== rtrim($contents, "\n")) {
                $this->put($path, $written);
            }
        }
    }

    /**
     * Whether a map has a layer of this name, inside a group or not.
     *
     * @param  array<mixed>  $layers
     */
    private function hasLayer(array $layers, string $name): bool
    {
        return array_any($layers, fn (mixed $layer): bool => is_object($layer)
            && (($layer->name ?? null) === $name || $this->hasLayer(is_array($layer->layers ?? null) ? $layer->layers : [], $name)));
    }

    /**
     * A map's tilesets with the props tileset written in: an image
     * collection, each tile a room piece at one facing, pinned by the middle
     * of its bottom edge. It
     * keeps its first GID once a map has it (Tiled renumbers it to follow
     * the ground tiles on save), unless the ground tiles have grown into it;
     * then it moves up and the pieces already placed move with it, so each
     * stays what it is.
     *
     * @param  array<mixed>  $tilesets
     * @param  array<mixed>  $layers  Pieces in these move with the tileset.
     * @param  list<array{id: int, image: string, imagewidth: int, imageheight: int, properties: list<array<string, mixed>>}>  $tiles
     * @return array<mixed>
     */
    private function withPropTileset(array $tilesets, array $layers, array $tiles, int $groundTiles): array
    {
        $at = null;
        $firstGid = self::PROPS_FIRSTGID;
        $groundFirst = 1;

        foreach ($tilesets as $i => $tileset) {
            if (is_object($tileset) && ($tileset->name ?? null) === self::PROPS && is_int($tileset->firstgid ?? null)) {
                [$at, $firstGid] = [$i, $tileset->firstgid];
            } elseif (is_object($tileset) && ($tileset->name ?? null) === self::TILESET && is_int($tileset->firstgid ?? null)) {
                $groundFirst = $tileset->firstgid;
            }
        }

        if ($groundFirst + $groundTiles > $firstGid) {
            $moved = max(self::PROPS_FIRSTGID, $groundFirst + $groundTiles);
            $this->shiftPropGids($layers, $firstGid, $moved);
            $firstGid = $moved;
        }

        $this->sizePieces($layers, $firstGid, array_column($tiles, null, 'id'));

        $block = [
            'columns' => 0,
            'firstgid' => $firstGid,
            'margin' => 0,
            'name' => self::PROPS,
            'objectalignment' => 'bottom',
            'spacing' => 0,
            'tilecount' => count($tiles),
            'tileheight' => max(0, ...array_column($tiles, 'imageheight')),
            'tiles' => $tiles,
            'tilewidth' => max(0, ...array_column($tiles, 'imagewidth')),
        ];

        if ($at === null) {
            $tilesets[] = $block;
        } else {
            $tilesets[$at] = $block;
        }

        return $tilesets;
    }

    /**
     * Sizes every placed piece to its picture. Tiled stretches a tile object
     * to its own width and height, so once a piece's art changes size, the
     * pieces already placed would show squashed in Tiled (the game only
     * reads where they stand).
     *
     * @param  array<mixed>  $layers
     * @param  array<int, array{id: int, image: string, imagewidth: int, imageheight: int, properties: list<array<string, mixed>>}>  $tiles  by tile id
     */
    private function sizePieces(array $layers, int $firstGid, array $tiles): void
    {
        foreach ($layers as $layer) {
            if (! is_object($layer)) {
                continue;
            }

            $this->sizePieces(is_array($layer->layers ?? null) ? $layer->layers : [], $firstGid, $tiles);

            foreach (is_array($layer->objects ?? null) ? $layer->objects : [] as $object) {
                $tile = $object instanceof stdClass && is_int($object->gid ?? null) ? ($tiles[($object->gid & 0x0FFFFFFF) - $firstGid] ?? null) : null;

                if ($tile !== null) {
                    $object->width = (float) $tile['imagewidth'];
                    $object->height = (float) $tile['imageheight'];
                }
            }
        }
    }

    /**
     * Renumbers every tile object at or past the props tileset's old first
     * GID to the new one, keeping Tiled's flip bits.
     *
     * @param  array<mixed>  $layers
     */
    private function shiftPropGids(array $layers, int $from, int $to): void
    {
        foreach ($layers as $layer) {
            if (! is_object($layer)) {
                continue;
            }

            $this->shiftPropGids(is_array($layer->layers ?? null) ? $layer->layers : [], $from, $to);

            foreach (is_array($layer->objects ?? null) ? $layer->objects : [] as $object) {
                if (is_object($object) && is_int($object->gid ?? null) && ($object->gid & 0x0FFFFFFF) >= $from) {
                    $object->gid += $to - $from;
                }
            }
        }
    }

    /** Writes through a temporary file, so nothing ever reads half a file. */
    private function put(string $path, string $contents): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temporary, $contents);
        rename($temporary, $path);
    }
}
