<?php

use App\Domain\World\Pathfinder;
use App\Domain\World\PathClock;
use App\Domain\World\WalkabilityGrid;

/**
 * Builds a grid from rows like "1 1 0", where 1 is walkable.
 */
function gridFrom(string ...$rows): WalkabilityGrid
{
    $cells = implode('', array_map(fn (string $row): string => str_replace(' ', '', $row), $rows));

    return new WalkabilityGrid(strlen(str_replace(' ', '', $rows[0])), count($rows), $cells, [[0, 0]]);
}

/**
 * @param  list<array{int, int}>  $path
 */
function expectValidWalk(WalkabilityGrid $grid, array $path): void
{
    foreach ($path as $i => [$x, $y]) {
        expect($grid->isWalkable($x, $y))->toBeTrue("tile ({$x}, {$y}) is blocked");

        if ($i === 0) {
            continue;
        }

        [$px, $py] = $path[$i - 1];
        expect(max(abs($x - $px), abs($y - $py)))->toBe(1, 'steps move to an adjacent tile');

        if ($x !== $px && $y !== $py) {
            expect($grid->isWalkable($x, $py) && $grid->isWalkable($px, $y))->toBeTrue("diagonal ({$px}, {$py}) → ({$x}, {$y}) cuts a corner");
        }
    }
}

/**
 * @param  list<array{int, int}>  $path
 */
function walkLength(array $path): float
{
    return PathClock::durationMs($path, 1000.0);
}

it('returns just the start when already at the goal', function () {
    expect((new Pathfinder)->find(gridFrom('1 1', '1 1'), [1, 1], [1, 1], 200))->toBe([[1, 1]]);
});

it('walks diagonally across open ground', function () {
    $grid = gridFrom('1 1 1 1', '1 1 1 1', '1 1 1 1', '1 1 1 1');

    expect((new Pathfinder)->find($grid, [0, 0], [3, 3], 200))->toBe([[0, 0], [1, 1], [2, 2], [3, 3]]);
});

it('mixes diagonal and straight steps into a shortest path', function () {
    $grid = gridFrom('1 1 1 1 1 1', '1 1 1 1 1 1', '1 1 1 1 1 1');
    $path = (new Pathfinder)->find($grid, [0, 0], [5, 2], 200);

    expectValidWalk($grid, $path);
    expect($path[0])->toBe([0, 0])
        ->and(end($path))->toBe([5, 2])
        ->and(count($path) - 1)->toBe(5)
        ->and(walkLength($path))->toEqualWithDelta(3 + 2 * M_SQRT2, 1e-9);
});

it('never cuts a corner past a blocked tile', function () {
    // (1, 0) is blocked, so (0, 0) → (1, 1) must go around through (0, 1).
    $grid = gridFrom('1 0', '1 1');

    expect((new Pathfinder)->find($grid, [0, 0], [1, 1], 200))->toBe([[0, 0], [0, 1], [1, 1]]);
});

it('cannot squeeze diagonally between two blocked tiles', function () {
    expect((new Pathfinder)->find(gridFrom('1 0', '0 1'), [0, 0], [1, 1], 200))->toBeNull();
});

it('routes around walls', function () {
    $grid = gridFrom(
        '1 1 1 1 1',
        '0 0 0 0 1',
        '1 1 1 1 1',
    );
    $path = (new Pathfinder)->find($grid, [0, 0], [0, 2], 200);

    expectValidWalk($grid, $path);
    // No diagonal past the wall ends, so the only route hugs the corridor.
    expect($path)->toBe([[0, 0], [1, 0], [2, 0], [3, 0], [4, 0], [4, 1], [4, 2], [3, 2], [2, 2], [1, 2], [0, 2]]);
});

it('returns null for an enclosed goal', function () {
    $grid = gridFrom(
        '1 1 1 1 1',
        '1 0 0 0 1',
        '1 0 1 0 1',
        '1 0 0 0 1',
    );

    expect((new Pathfinder)->find($grid, [0, 0], [2, 2], 200))->toBeNull();
});

it('returns null for a blocked or out of bounds goal or start', function (array $start, array $goal) {
    expect((new Pathfinder)->find(gridFrom('1 1 0', '1 1 1'), $start, $goal, 200))->toBeNull();
})->with([
    'blocked goal' => [[0, 0], [2, 0]],
    'goal past the right edge' => [[0, 0], [3, 1]],
    'negative goal' => [[0, 0], [-1, 0]],
    'blocked start' => [[2, 0], [0, 0]],
]);

it('caps paths at the maximum number of steps', function () {
    $corridor = gridFrom(str_repeat('1 ', 202));
    $pathfinder = new Pathfinder;

    $longest = $pathfinder->find($corridor, [0, 0], [200, 0], 200);

    expect($longest)->toHaveCount(201)
        ->and($pathfinder->find($corridor, [0, 0], [201, 0], 200))->toBeNull();
});

it('counts steps rather than straight-line distance against the cap', function () {
    // The goal is 2 tiles away as the crow flies, but the only route is 10 steps.
    $grid = gridFrom(
        '1 1 1 1 1',
        '0 0 0 0 1',
        '1 1 1 1 1',
    );

    expect((new Pathfinder)->find($grid, [0, 0], [0, 2], 9))->toBeNull()
        ->and((new Pathfinder)->find($grid, [0, 0], [0, 2], 10))->toHaveCount(11);
});
