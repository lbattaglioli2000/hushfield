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

    /** How far down the block its top face begins, in game pixels. */
    public const int FACE_TOP = 16;

    /** The art-pixel row each slab height's top face begins on, in its 32 × 32 source. */
    private const array SLAB_TOP = ['full' => 7, 'half' => 11];

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

    public function __construct(private readonly TileRegistry $tiles) {}

    /** @return array{version: string, files: array<string, string>} */
    public function build(bool $maps = true): array
    {
        $sources = (string) config('hushfield.assets.sources');
        $files = $this->sourceFiles($sources);
        $this->checkProvenance($sources, $files);

        $slots = $this->tiles->slots();
        $sheet = $this->tileSheet($sources, $files, $slots);
        $outputs = ['tiles' => Png::encode($sheet)];

        foreach ($files as $file) {
            if (preg_match('#^ui/([a-z0-9-]+)\.png$#', $file, $m)) {
                $outputs["ui/{$m[1]}"] = Png::encode($this->ui($sources, $file, $m[1]));
            }
        }

        $index = $this->write($outputs);

        if ($maps) {
            $this->writeMaps($outputs['tiles'], $sheet, $slots);
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
                || preg_match('#^ui/[a-z0-9-]+\.png$#', (string) $png);

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
     * A source checked against its size in game pixels (null: any) divided by
     * its kind's scale, then multiplied up to game size.
     */
    private function scaled(string $sources, string $file, string $kind, ?int $width, ?int $height): Image
    {
        $scale = $this->scale($kind);
        $image = Png::decode((string) file_get_contents("{$sources}/{$file}"), "art/{$file}");
        $want = fn (?int $size, int $got): int => $size === null ? $got : intdiv($size, $scale);
        [$wantWidth, $wantHeight] = [$want($width, $image->width), $want($height, $image->height)];

        if ($image->width === $wantWidth && $image->height === $wantHeight) {
            return $image->scale($scale);
        }

        if ($scale > 1 && $image->width === $wantWidth * $scale && $image->height === $wantHeight * $scale && $image->isScaledUp($scale)) {
            throw new InvalidAssetException("art/{$file} is already scaled up to {$image->width}×{$image->height}. Export it at 100%: {$wantWidth}×{$wantHeight}.");
        }

        throw new InvalidAssetException("art/{$file} is {$image->width}×{$image->height}; it must be {$wantWidth}×{$wantHeight}.");
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
     * left falls. A half slab's top face sits 4 art pixels lower.
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
     * its sides reach that edge; a full block's sides stand 8 tall at the
     * canvas's left and right edges, a half slab's 4, its top face 4 lower.
     * Art drawn by hand is a pixel off here and there, so each test allows a
     * little.
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

        if (! $half && $rows(15, 18) < 3) {
            throw new InvalidAssetException("art/{$file} is too short for a full block: its sides should stand 8 pixels tall at the canvas's edges. Is it a half slab?");
        }

        if ($half && $rows(11, 16) > 1) {
            throw new InvalidAssetException("art/{$file} is too tall for a half slab: its sides should stand 4 pixels tall, its top face 4 pixels lower than a full block's.");
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
     * @param  array<string, string>  $outputs
     * @return array{version: string, files: array<string, string>}
     */
    private function write(array $outputs): array
    {
        $dir = (string) config('hushfield.assets.output');
        $files = [];

        foreach ($outputs as $name => $bytes) {
            $file = $name.'.'.substr(hash('sha256', $bytes), 0, 8).'.png';
            $files[$name] = $file;

            if (! is_file("{$dir}/{$file}")) {
                File::ensureDirectoryExists(dirname("{$dir}/{$file}"));
                $this->put("{$dir}/{$file}", $bytes);
            }
        }

        ksort($files);
        $index = ['version' => substr(hash('sha256', (string) json_encode($files)), 0, 16), 'files' => $files];
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
                        'tileoffset' => ['x' => 0, 'y' => self::FACE_TOP],
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
