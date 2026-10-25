<?php

use App\Domain\Identity\AgePolicy;
use Carbon\CarbonImmutable;

function on(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'America/New_York');
}

it('meets the minimum age only once the last possible birthday has passed', function (int $month, int $year, string $today, bool $expected) {
    expect((new AgePolicy)->meetsMinimumAge($month, $year, on($today)))->toBe($expected);
})->with([
    'turns 13 this month, not yet the last day' => [10, 2013, '2026-10-30', false],
    'turns 13 this month, on the last day' => [10, 2013, '2026-10-31', true],
    'turned 13 last month' => [9, 2013, '2026-10-01', true],
    'turns 13 next month' => [11, 2013, '2026-10-25', false],
    'turns 13 next year' => [1, 2014, '2026-12-31', false],
    'December birthday, on 30 December' => [12, 2012, '2025-12-30', false],
    'December birthday, on 31 December' => [12, 2012, '2025-12-31', true],
    'December birthday, on 1 January' => [12, 2012, '2026-01-01', true],
    'January birthday, on 1 January' => [1, 2013, '2026-01-01', false],
    'January birthday, on 31 January' => [1, 2013, '2026-01-31', true],
    'February birthday in a leap year, on the 28th' => [2, 2011, '2024-02-28', false],
    'February birthday in a leap year, on the 29th' => [2, 2011, '2024-02-29', true],
    'February birthday in a common year, on the 28th' => [2, 2012, '2025-02-28', true],
    'much older' => [6, 1980, '2026-10-25', true],
]);

it('treats someone as a minor until a 31 December eighteenth birthday has passed', function (int $year, string $today, bool $expected) {
    expect((new AgePolicy)->isMinor($year, on($today)))->toBe($expected);
})->with([
    'born 2008, 30 December 2026' => [2008, '2026-12-30', true],
    'born 2008, 31 December 2026' => [2008, '2026-12-31', false],
    'born 2008, 1 January 2026' => [2008, '2026-01-01', true],
    'born 2008, 1 January 2027' => [2008, '2027-01-01', false],
    'born 2007, mid 2026' => [2007, '2026-06-15', false],
    'born 2013, mid 2026' => [2013, '2026-06-15', true],
]);

it('uses Hushfield time when no date is given', function () {
    // 03:00 UTC on 31 December is still 30 December in New York.
    $this->travelTo(CarbonImmutable::parse('2026-12-31 03:00:00', 'UTC'));

    expect((new AgePolicy)->isMinor(2008))->toBeTrue()
        ->and((new AgePolicy)->meetsMinimumAge(12, 2013))->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-12-31 06:00:00', 'UTC'));

    expect((new AgePolicy)->isMinor(2008))->toBeFalse()
        ->and((new AgePolicy)->meetsMinimumAge(12, 2013))->toBeTrue();
});
