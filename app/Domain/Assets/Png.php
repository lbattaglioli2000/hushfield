<?php

namespace App\Domain\Assets;

/**
 * Reads and writes PNGs without losing a bit. GD keeps only 7 bits of alpha,
 * so a GD round trip changes any half-transparent pixel; this reads every
 * non-interlaced PNG an art tool exports (grey, RGB, palette, with alpha, 8
 * or 16 bits per channel) into 8-bit RGBA, and writes 8-bit RGBA.
 */
final class Png
{
    private const string SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** Bytes per pixel and channels for each colour type. */
    private const array CHANNELS = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];

    public static function decode(string $bytes, string $name = 'image'): Image
    {
        if (! str_starts_with($bytes, self::SIGNATURE)) {
            throw new InvalidAssetException("{$name} is not a PNG.");
        }

        $offset = 8;
        $header = null;
        $data = '';
        $palette = '';
        $transparency = '';

        while ($offset + 8 <= strlen($bytes)) {
            $length = self::uint32($bytes, $offset);
            $type = substr($bytes, $offset + 4, 4);
            $chunk = substr($bytes, $offset + 8, $length);
            $offset += 12 + $length;

            match ($type) {
                'IHDR' => $header = unpack('Nwidth/Nheight/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $chunk),
                'PLTE' => $palette = $chunk,
                'tRNS' => $transparency = $chunk,
                'IDAT' => $data .= $chunk,
                default => null,
            };

            if ($type === 'IEND') {
                break;
            }
        }

        if (! is_array($header)) {
            throw new InvalidAssetException("{$name} has no PNG header.");
        }

        [$width, $height, $depth, $type, $interlace] = array_map(
            fn (string $field): int => is_int($header[$field] ?? null) ? $header[$field] : 0,
            ['width', 'height', 'depth', 'type', 'interlace'],
        );

        if ($interlace !== 0) {
            throw new InvalidAssetException("{$name} is interlaced. Export it without interlacing.");
        }

        if (! isset(self::CHANNELS[$type]) || ! in_array($depth, $type === 3 ? [1, 2, 4, 8] : ($type === 0 ? [1, 2, 4, 8, 16] : [8, 16]), true)) {
            throw new InvalidAssetException("{$name} uses a PNG format the build can't read (colour type {$type}, {$depth}-bit).");
        }

        $raw = @gzuncompress($data);

        if ($raw === false) {
            throw new InvalidAssetException("{$name} has corrupt image data.");
        }

        $bitsPerPixel = self::CHANNELS[$type] * $depth;
        $stride = intdiv($width * $bitsPerPixel + 7, 8);
        $step = max(1, intdiv($bitsPerPixel, 8));

        if (strlen($raw) < ($stride + 1) * $height) {
            throw new InvalidAssetException("{$name} has truncated image data.");
        }

        $rows = self::unfilter($raw, $stride, $height, $step);

        return new Image($width, $height, self::toRgba($rows, $width, $type, $depth, $palette, $transparency));
    }

    public static function encode(Image $image): string
    {
        $raw = '';
        $stride = $image->width * 4;

        for ($y = 0; $y < $image->height; $y++) {
            $raw .= "\0".substr($image->pixels, $y * $stride, $stride);
        }

        return self::SIGNATURE
            .self::chunk('IHDR', pack('NNCCCCC', $image->width, $image->height, 8, 6, 0, 0, 0))
            .self::chunk('IDAT', (string) gzcompress($raw, 9))
            .self::chunk('IEND', '');
    }

    /** @return list<string> */
    private static function unfilter(string $raw, int $stride, int $height, int $step): array
    {
        $rows = [];
        $previous = str_repeat("\0", $stride);

        for ($y = 0; $y < $height; $y++) {
            $filter = ord($raw[$y * ($stride + 1)]);
            $line = substr($raw, $y * ($stride + 1) + 1, $stride);

            if ($filter !== 0) {
                for ($x = 0; $x < $stride; $x++) {
                    $a = $x >= $step ? ord($line[$x - $step]) : 0;
                    $b = ord($previous[$x]);
                    $c = $x >= $step ? ord($previous[$x - $step]) : 0;
                    $predicted = match ($filter) {
                        1 => $a,
                        2 => $b,
                        3 => ($a + $b) >> 1,
                        4 => self::paeth($a, $b, $c),
                        default => throw new InvalidAssetException("Unknown PNG filter {$filter}."),
                    };
                    $line[$x] = chr((ord($line[$x]) + $predicted) & 0xFF);
                }
            }

            $rows[] = $line;
            $previous = $line;
        }

        return $rows;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    /** @param  list<string>  $rows */
    private static function toRgba(array $rows, int $width, int $type, int $depth, string $palette, string $transparency): string
    {
        if ($type === 6 && $depth === 8) {
            return implode('', $rows);
        }

        $pixels = '';
        $channels = self::CHANNELS[$type];
        // A grey or RGB image's tRNS names one colour that is fully transparent.
        $clear = $transparency !== '' && in_array($type, [0, 2], true) ? array_values((array) unpack('n*', $transparency)) : null;

        foreach ($rows as $line) {
            for ($x = 0; $x < $width; $x++) {
                $samples = [];

                for ($channel = 0; $channel < $channels; $channel++) {
                    $samples[] = self::sample($line, $x * $channels + $channel, $depth);
                }

                // Grey samples below 8 bits scale up to 0–255; palette indexes stay as they are.
                $wide = $depth === 16 ? array_map(fn (int $s): int => $s >> 8, $samples)
                    : ($depth < 8 && $type === 0 ? array_map(fn (int $s): int => intdiv($s * 255, (1 << $depth) - 1), $samples) : $samples);

                [$r, $g, $b, $a] = match ($type) {
                    0 => [$wide[0], $wide[0], $wide[0], 255],
                    2 => [$wide[0], $wide[1], $wide[2], 255],
                    3 => [
                        ord($palette[$samples[0] * 3] ?? "\0"),
                        ord($palette[$samples[0] * 3 + 1] ?? "\0"),
                        ord($palette[$samples[0] * 3 + 2] ?? "\0"),
                        isset($transparency[$samples[0]]) ? ord($transparency[$samples[0]]) : 255,
                    ],
                    4 => [$wide[0], $wide[0], $wide[0], $wide[1]],
                    default => $wide,
                };

                if ($clear !== null && array_slice($samples, 0, count($clear)) === $clear) {
                    $a = 0;
                }

                $pixels .= chr($r).chr($g).chr($b).chr($a);
            }
        }

        return $pixels;
    }

    /** The n-th sample of a row, `depth` bits wide. */
    private static function sample(string $line, int $n, int $depth): int
    {
        return match ($depth) {
            8 => ord($line[$n]),
            16 => (ord($line[$n * 2]) << 8) | ord($line[$n * 2 + 1]),
            default => (ord($line[intdiv($n * $depth, 8)]) >> (8 - $depth - ($n * $depth) % 8)) & ((1 << $depth) - 1),
        };
    }

    private static function uint32(string $bytes, int $offset): int
    {
        $value = strlen($bytes) >= $offset + 4 ? unpack('N', $bytes, $offset) : false;

        return is_array($value) && is_int($value[1]) ? $value[1] : 0;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
