<?php

namespace App\Events\World;

use App\Domain\World\PlayerState;
use App\Events\RoomEvent;

final class PlayerJoined extends RoomEvent
{
    public function __construct(int $instanceId, public readonly PlayerState $player)
    {
        parent::__construct($instanceId);
    }

    protected function payload(): array
    {
        return ['player' => $this->player->toArray()];
    }
}
