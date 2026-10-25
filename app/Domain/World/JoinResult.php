<?php

namespace App\Domain\World;

use App\Models\RoomInstance;

final readonly class JoinResult
{
    public function __construct(
        public RoomInstance $instance,
        public StoredPath $position,
        public bool $overflowed,
    ) {}
}
