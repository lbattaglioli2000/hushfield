<?php

namespace App\Domain\World;

use InvalidArgumentException;

/**
 * Evaluates where a walker is along a path at a given time.
 *
 * Pure and deterministic. resources/js/game/pathClock.ts is a line-for-line
 * twin; both are tested against tests/fixtures/path-clock.json. Change them together.
 *
 * Orthogonal steps are 1 tile long and diagonal steps are √2 tiles long.
 */
final class PathClock
{
    /**
     * @param  list<array{int, int}>  $path
     */
    public static function positionAt(array $path, int $startedAtMs, float $speedTilesPerSec, int $nowMs): PathPosition
    {
        if ($path === []) {
            throw new InvalidArgumentException('A path needs at least one tile.');
        }

        $remaining = max(0, $nowMs - $startedAtMs) * $speedTilesPerSec / 1000;
        $last = count($path) - 1;

        for ($i = 0; $i < $last; $i++) {
            $from = $path[$i];
            $to = $path[$i + 1];
            $length = self::stepLength($from, $to);

            if ($remaining < $length) {
                $progress = $remaining / $length;
                $x = $from[0] + ($to[0] - $from[0]) * $progress;
                $y = $from[1] + ($to[1] - $from[1]) * $progress;
                $tile = $progress < 0.5 ? $from : $to;

                return new PathPosition(
                    x: $x,
                    y: $y,
                    tile: $tile,
                    offset: [$x - $tile[0], $y - $tile[1]],
                    segment: $i,
                    from: $from,
                    to: $to,
                    progress: $progress,
                    segmentElapsedMs: $remaining / $speedTilesPerSec * 1000,
                    finished: false,
                );
            }

            $remaining -= $length;
        }

        $end = $path[$last];

        return new PathPosition(
            x: (float) $end[0],
            y: (float) $end[1],
            tile: $end,
            offset: [0.0, 0.0],
            segment: $last,
            from: $end,
            to: $end,
            progress: 0.0,
            segmentElapsedMs: 0.0,
            finished: true,
        );
    }

    /**
     * Total walking time for the whole path, in milliseconds.
     *
     * @param  list<array{int, int}>  $path
     */
    public static function durationMs(array $path, float $speedTilesPerSec): float
    {
        $length = 0.0;

        for ($i = 0, $last = count($path) - 1; $i < $last; $i++) {
            $length += self::stepLength($path[$i], $path[$i + 1]);
        }

        return $length / $speedTilesPerSec * 1000;
    }

    /**
     * @param  array{int, int}  $from
     * @param  array{int, int}  $to
     */
    public static function stepLength(array $from, array $to): float
    {
        return $from[0] !== $to[0] && $from[1] !== $to[1] ? M_SQRT2 : 1.0;
    }
}
