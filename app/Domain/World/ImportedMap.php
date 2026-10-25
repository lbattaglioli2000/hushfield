<?php

namespace App\Domain\World;

final readonly class ImportedMap
{
    public function __construct(
        public string $version,
        public WalkabilityGrid $grid,
    ) {}
}
