<?php

namespace App\Domain\Chat;

enum FilterLevel: string
{
    /** Blocks every blocked term plus links, email addresses, and phone numbers. The default, and always used for minors. */
    case Strict = 'strict';

    /** Blocks medium and high severity terms only. */
    case Standard = 'standard';

    public function blocks(BlockedTermSeverity $severity): bool
    {
        return match ($this) {
            self::Strict => true,
            self::Standard => $severity !== BlockedTermSeverity::Low,
        };
    }

    public function blocksContactDetails(): bool
    {
        return $this === self::Strict;
    }
}
