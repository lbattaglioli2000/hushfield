<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A staff moderation action the server refuses. The message is for staff, not players.
 */
final class ModerationActionRefused extends RuntimeException
{
    public static function staffTarget(): self
    {
        return new self('Staff accounts cannot be sanctioned.');
    }

    public static function reportNotOpen(): self
    {
        return new self('This report has already been resolved.');
    }
}
