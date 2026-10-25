<?php

namespace App\Domain\Chat;

/**
 * Swappable so a third-party moderation API can replace the blocked-terms filter later.
 */
interface ChatFilter
{
    public function filter(string $body, FilterLevel $level): FilterResult;
}
