<?php

namespace App\Events\World;

use App\Domain\World\StoredPath;
use App\Events\RoomEvent;

final class PlayerMoved extends RoomEvent
{
    public function __construct(
        int $instanceId,
        public readonly int $userId,
        public readonly StoredPath $path,
    ) {
        parent::__construct($instanceId);
    }

    protected function payload(): array
    {
        return [
            'user_id' => $this->userId,
            ...$this->path->toArray(),
        ];
    }
}
