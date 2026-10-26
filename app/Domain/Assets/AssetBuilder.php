<?php

namespace App\Domain\Assets;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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

    /** UI images the game uses, and their size in game pixels (null: any size). */
    private const array UI = [
        'marker' => [64, 32],
        'hover' => [64, 32],
        'resident-placeholder' => null,
    ];

    /** A building's, a wall style's or an item's slug. */
    private const string SLUG = '[a-z0-9-]+';

    /** Wall panel parts, by side: plain, a doorway, and (not drawn yet) a window. */
    public const string WALL_PART = '(left|right)(-door|-window)?';

    /** @var list<string> What the last build let through but wants fixed. */
    private array $warnings = [];

    public function __construct(private readonly TileRegistry $tiles) {}

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array{version: string, files: array<string, string>, sizes: array<string, array{int, int}>} */
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

        foreach ($files as $file) {
            $name = substr($file, 0, -4);

            if (preg_match('#^ui/('.self::SLUG.')\.png$#', $file, $m)) {
                $outputs[$name] = $this->ui($sources, $file, $m[1]);
            } elseif (preg_match('#^buildings/('.self::SLUG.')\.png$#', $file, $m)) {
                $buildings ??= $this->buildingFootprints();
                $outputs[$name] = $this->building($sources, $file, $buildings[$m[1]] ?? null);
            } elseif (preg_match('#^walls/'.self::SLUG.'/'.self::WALL_PART.'\.png$#', $file)) {
                $outputs[$name] = $this->wall($sources, $file, str_starts_with(basename($file), 'left'));
            } elseif (preg_match('#^items/'.self::SLUG.'/[0-3]\.png$#', $file)) {
                $outputs[$name] = $this->furniture($sources, $file);
            }
        }

        $index = $this->write($outputs);

        if ($maps) {
            $this->writeMaps(Png::encode($sheet), $sheet, $slots);
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
            $known = in_array($file, ['provenance.json', 'tiles/tiles.json'], true)
                || preg_match('#^tiles/'.TileRegistry::NAME.'\.png$#', (string) $png)
                || preg_match('#^(ui|buildings)/'.self::SLUG.'\.png$#', (string) $png)
                || preg_match('#^walls/'.self::SLUG.'/'.self::WALL_PART.'\.png$#', (string) $png)
                || preg_match('#^items/'.self::SLUG.'/[0-3]\.png$#', (string) $png);

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
            fn (string $file): bool => ! in_array($file, ['provenance.json', 'tiles/tiles.json'], true)
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
        $this->checkFootprint($file, $art, $wide, "a {$wide} × {$deep} footprint");

        return $art->scale($this->scale('buildings'));
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
            $problem = $this->footprintProblem($art, $wide * $tile, $file);

            if ($problem === null) {
                return $art->scale($scale);
            }

            $first ??= $problem;
        }

        throw new InvalidAssetException($first ?? "art/{$file} hangs below its footprint.");
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

    /** Fails unless nothing hangs below the footprint diamond whose bottom corner is `wide` tiles in. */
    private function checkFootprint(string $file, Image $art, int $wide, string $what): void
    {
        $problem = $this->footprintProblem($art, $wide * intdiv(32, $this->scale('buildings')), $file);

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
    private function footprintProblem(Image $art, int $corner, string $file): ?string
    {
        $rows = intdiv($art->width, 2) + 1;

        for ($y = max(0, $art->height - $rows); $y < $art->height; $y++) {
            for ($x = 0; $x < $art->width; $x++) {
                $edge = $art->height - abs($x + 0.5 - $corner) / 2;

                if ($y > $edge + 1 && $art->alphaAt($x, $y) > 0) {
                    return "art/{$file} has pixels below its footprint diamond at ({$x}, {$y}).";
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
        $scale = $this->scale($kind);
        $image = Png::decode((string) file_get_contents("{$sources}/{$file}"), "art/{$file}");
        $wantWidth = $width === null ? null : intdiv($width, $scale);
        $wantHeight = $height === null ? null : intdiv($height, $scale);
        $matches = fn (int $factor): bool => ($wantWidth === null || $image->width === $wantWidth * $factor)
            && ($wantHeight === null || $image->height === $wantHeight * $factor);

        if ($scale > 1 && $matches($scale) && $image->isScaledUp($scale)) {
            $expected = ($wantWidth ?? intdiv($image->width, $scale)).'×'.($wantHeight ?? intdiv($image->height, $scale));

            throw new InvalidAssetException("art/{$file} is already scaled up to {$image->width}×{$image->height}. Export it at 100%: {$expected}.");
        }

        if (! $matches(1)) {
            $size = $wantHeight === null ? "{$wantWidth} wide" : ($wantWidth === null ? "{$wantHeight} tall" : "{$wantWidth}×{$wantHeight}");

            throw new InvalidAssetException("art/{$file} is {$image->width}×{$image->height}; it must be {$size}.");
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
     * @return array{version: string, files: array<string, string>, sizes: array<string, array{int, int}>}
     */
    private function write(array $outputs): array
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
        $index = ['version' => substr(hash('sha256', (string) json_encode($files)), 0, 16), 'files' => $files, 'sizes' => $sizes];
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
     * sheet the game draws.
     *
     * @param  list<string|null>  $slots
     */
    private function writeMaps(string $png, Image $sheet, array $slots): void
    {
        $maps = (string) config('hushfield.maps.path');

        if (! is_file("{$maps}/ground.png") || file_get_contents("{$maps}/ground.png") !== $png) {
            $this->put("{$maps}/ground.png", $png);
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

            // Tiled's compact export, byte for byte, so only the tileset shows in a diff.
            $written = (string) json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

            if ($written !== rtrim($contents, "\n")) {
                $this->put($path, $written);
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
