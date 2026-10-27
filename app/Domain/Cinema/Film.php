<?php

namespace App\Domain\Cinema;

/**
 * A YouTube video that YouTube says the VFA may show: embeddable, finished,
 * not live, not age-restricted, and of a length that fits the programme.
 */
final readonly class Film
{
    public function __construct(
        public string $videoId,
        public string $title,
        public ?string $channel,
        public int $durationSeconds,
        public bool $madeForKids,
    ) {}
}
