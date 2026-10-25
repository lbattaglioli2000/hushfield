<?php

use App\Domain\World\PathClock;

/**
 * Shared with the TypeScript twin (resources/js/game/pathClock.ts). Expected
 * values in the fixture were worked out by hand, not produced by PathClock.
 *
 * @return array<string, array{array<string, mixed>}>
 */
function pathClockCases(): array
{
    /** @var list<array<string, mixed>> $cases */
    $cases = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/path-clock.json'), true, flags: JSON_THROW_ON_ERROR);

    return collect($cases)->mapWithKeys(fn (array $case): array => [$case['name'] => [$case]])->all();
}

it('matches the shared fixture', function (array $case) {
    $position = PathClock::positionAt($case['path'], $case['started_at_ms'], (float) $case['speed'], $case['now_ms']);
    $expected = $case['expected'];

    expect($position->tile)->toBe($expected['tile'])
        ->and($position->offset[0])->toEqualWithDelta($expected['offset'][0], 1e-9)
        ->and($position->offset[1])->toEqualWithDelta($expected['offset'][1], 1e-9)
        ->and($position->segment)->toBe($expected['segment'])
        ->and($position->finished)->toBe($expected['finished'])
        ->and($position->x)->toEqualWithDelta($expected['tile'][0] + $expected['offset'][0], 1e-9)
        ->and($position->y)->toEqualWithDelta($expected['tile'][1] + $expected['offset'][1], 1e-9);
})->with(pathClockCases());

it('covers every boundary the twin implementations must agree on', function () {
    expect(array_keys(pathClockCases()))->toHaveCount(15);
});

it('takes sqrt(2) times longer on a diagonal step', function () {
    expect(PathClock::durationMs([[0, 0], [1, 0]], 4))->toEqualWithDelta(250, 1e-9)
        ->and(PathClock::durationMs([[0, 0], [1, 1]], 4))->toEqualWithDelta(250 * M_SQRT2, 1e-9)
        ->and(PathClock::durationMs([[0, 0], [1, 1], [2, 1], [2, 2]], 4))->toEqualWithDelta(500 + 250 * M_SQRT2, 1e-9)
        ->and(PathClock::durationMs([[3, 3]], 4))->toBe(0.0);
});

it('refuses an empty path', function () {
    PathClock::positionAt([], 0, 4, 0);
})->throws(InvalidArgumentException::class);
