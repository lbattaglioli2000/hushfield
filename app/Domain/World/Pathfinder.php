<?php

namespace App\Domain\World;

use SplPriorityQueue;

/**
 * A* over a walkability grid with 8-directional movement and no diagonal
 * corner-cutting. Neighbour order and tie-breaking match
 * resources/js/game/astar.ts so client prediction usually agrees with the server.
 */
final class Pathfinder
{
    /** N, E, S, W, then NE, SE, SW, NW. */
    private const array DIRECTIONS = [[0, -1], [1, 0], [0, 1], [-1, 0], [1, -1], [1, 1], [-1, 1], [-1, -1]];

    /**
     * @param  array{int, int}  $start
     * @param  array{int, int}  $goal
     * @return list<array{int, int}>|null The path from start to goal inclusive, or null when unreachable.
     */
    public function find(WalkabilityGrid $grid, array $start, array $goal, int $maxSteps): ?array
    {
        [$goalX, $goalY] = $goal;

        if (! $grid->isWalkable($goalX, $goalY) || ! $grid->isWalkable($start[0], $start[1])) {
            return null;
        }

        if ($start === $goal) {
            return [$start];
        }

        $width = $grid->width;
        $startKey = $start[1] * $width + $start[0];
        $goalKey = $goalY * $width + $goalX;

        /** @var array<int, float> $cost */
        $cost = [$startKey => 0.0];
        /** @var array<int, int> $cameFrom */
        $cameFrom = [];
        /** @var array<int, true> $closed */
        $closed = [];

        $open = new SplPriorityQueue;
        $open->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $sequence = 0;
        $open->insert($startKey, $this->priority($this->heuristic($start[0], $start[1], $goalX, $goalY), 0.0, $sequence++));

        while (! $open->isEmpty()) {
            /** @var int $current */
            $current = $open->extract();

            if (isset($closed[$current])) {
                continue;
            }

            if ($current === $goalKey) {
                return $this->reconstruct($cameFrom, $current, $width, $maxSteps);
            }

            $closed[$current] = true;
            $x = $current % $width;
            $y = intdiv($current, $width);

            foreach (self::DIRECTIONS as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;

                if (! $grid->isWalkable($nx, $ny)) {
                    continue;
                }

                $diagonal = $dx !== 0 && $dy !== 0;

                if ($diagonal && (! $grid->isWalkable($x + $dx, $y) || ! $grid->isWalkable($x, $y + $dy))) {
                    continue;
                }

                $next = $ny * $width + $nx;

                if (isset($closed[$next])) {
                    continue;
                }

                $tentative = $cost[$current] + ($diagonal ? M_SQRT2 : 1.0);

                if (! isset($cost[$next]) || $tentative < $cost[$next]) {
                    $cost[$next] = $tentative;
                    $cameFrom[$next] = $current;
                    $h = $this->heuristic($nx, $ny, $goalX, $goalY);
                    $open->insert($next, $this->priority($tentative + $h, $h, $sequence++));
                }
            }
        }

        return null;
    }

    private function heuristic(int $x, int $y, int $goalX, int $goalY): float
    {
        $dx = abs($x - $goalX);
        $dy = abs($y - $goalY);

        return ($dx + $dy) + (M_SQRT2 - 2) * min($dx, $dy);
    }

    /**
     * SplPriorityQueue extracts the highest priority first, so negate:
     * lowest f, then lowest h, then earliest insertion.
     *
     * @return array{float, float, int}
     */
    private function priority(float $f, float $h, int $sequence): array
    {
        return [-$f, -$h, -$sequence];
    }

    /**
     * @param  array<int, int>  $cameFrom
     * @return list<array{int, int}>|null
     */
    private function reconstruct(array $cameFrom, int $current, int $width, int $maxSteps): ?array
    {
        $path = [[$current % $width, intdiv($current, $width)]];

        while (isset($cameFrom[$current])) {
            $current = $cameFrom[$current];
            $path[] = [$current % $width, intdiv($current, $width)];

            if (count($path) - 1 > $maxSteps) {
                return null;
            }
        }

        return array_reverse($path);
    }
}
