<?php

namespace App\Domain\Chat;

enum BlockedTermMatchType: string
{
    /** Matches a whole word (or run of whole words) after normalization. */
    case Exact = 'exact';

    /** Matches any word that contains the term after normalization. */
    case Contains = 'contains';

    /** A PCRE pattern (without delimiters) matched against the normalized words. */
    case Regex = 'regex';
}
