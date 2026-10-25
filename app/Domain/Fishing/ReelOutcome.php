<?php

namespace App\Domain\Fishing;

/**
 * How a reel went, judged on the server's clock against the cast's bite window.
 */
enum ReelOutcome: string
{
    case Landed = 'landed';

    /** Reeled before anything bit. */
    case Early = 'early';

    /** Reeled after the bite had let go. */
    case Late = 'late';
}
