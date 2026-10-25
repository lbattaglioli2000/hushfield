<?php

namespace App\Domain\Chat;

final readonly class FilterResult
{
    /**
     * @param  list<string>  $matches  The blocked terms or pattern names that matched, for moderators.
     */
    public function __construct(
        public string $body,
        public bool $wasFiltered,
        public array $matches = [],
    ) {}
}
