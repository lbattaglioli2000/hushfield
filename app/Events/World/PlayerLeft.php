<?php

namespace App\Events\World;

use App\Events\RoomEvent;

final class PlayerLeft extends RoomEvent
{
    public function __construct(int $instanceId, public readonly int $userId)
    {
        parent::__construct($instanceId);
    }

    protected function payload(): array
    {
        return ['user_id' => $this->userId];
    }
}
