<?php

namespace App\Events\World;

use App\Events\WorldEvent;
use Illuminate\Broadcasting\PresenceChannel;

final class PlayerLeft extends WorldEvent
{
    public function __construct(
        public readonly int $instanceId,
        public readonly int $userId,
    ) {
        parent::__construct();
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("room.{$this->instanceId}");
    }

    public function broadcastAs(): string
    {
        return 'PlayerLeft';
    }

    protected function payload(): array
    {
        return ['user_id' => $this->userId];
    }
}
