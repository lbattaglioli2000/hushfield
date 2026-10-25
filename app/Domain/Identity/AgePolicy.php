<?php

namespace App\Domain\Identity;

use Carbon\CarbonImmutable;

/**
 * Age rules computed from birth month and year only. Both answers err on the
 * side of treating someone as younger, because the exact day is never asked for.
 */
final class AgePolicy
{
    /**
     * Old enough only once the minimum birthday has certainly passed, assuming
     * the birthday falls on the last day of the birth month.
     */
    public function meetsMinimumAge(int $birthMonth, int $birthYear, ?CarbonImmutable $today = null): bool
    {
        $today ??= $this->today();
        $certainBirthday = CarbonImmutable::create($birthYear + (int) config('hushfield.accounts.minimum_age'), $birthMonth, 1, 0, 0, 0, $today->timezone)
            ?->endOfMonth()
            ->startOfDay();

        return $certainBirthday !== null && $today->startOfDay()->gte($certainBirthday);
    }

    /**
     * A minor until the adult birthday has certainly passed. With only the
     * birth year stored, that means assuming a 31 December birthday.
     */
    public function isMinor(int $birthYear, ?CarbonImmutable $today = null): bool
    {
        $today ??= $this->today();
        $youngestPossibleAge = $today->year - $birthYear - ($today->month === 12 && $today->day === 31 ? 0 : 1);

        return $youngestPossibleAge < (int) config('hushfield.accounts.adult_age');
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('hushfield.timezone'));
    }
}
