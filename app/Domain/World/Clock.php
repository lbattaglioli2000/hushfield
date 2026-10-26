<?php

namespace App\Domain\World;

use Carbon\CarbonImmutable;

/**
 * Server time in epoch milliseconds, the only clock movement is evaluated
 * against, and the date in Hushfield, which world logic counts days by.
 */
final class Clock
{
    /**
     * Now, in Hushfield time (config hushfield.timezone). A Hushfield day, for
     * the stipend or the fishing cap, runs from its midnight to the next.
     */
    public static function hushfieldNow(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('hushfield.timezone'));
    }

    public static function nowMs(): int
    {
        return (int) now()->getTimestampMs();
    }
}
