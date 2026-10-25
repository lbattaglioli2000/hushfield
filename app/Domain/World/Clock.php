<?php

namespace App\Domain\World;

/**
 * Server time in epoch milliseconds, the only clock movement is evaluated against.
 */
final class Clock
{
    public static function nowMs(): int
    {
        return (int) now()->getTimestampMs();
    }
}
