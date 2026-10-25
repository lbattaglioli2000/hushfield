<?php

namespace App\Events\World;

use App\Domain\World\StoredPath;
use App\Events\WorldEvent;
use Illuminate\Broadcasting\PresenceChannel;

final class PlayerMoved extends WorldEvent
{
    public function __construct(
        public readonly int $instanceId,
        public readonly int $userId,
        public readonly StoredPath $path,
    ) {
        parent::__construct();
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("room.{$this->instanceId}");
    }

    public function broadcastAs(): string
    {
        return 'PlayerMoved';
    }

    protected function payload(): array
    {
        return [
            'user_id' => $this->userId,
            ...$this->path->toArray(),
        ];
    }
}
