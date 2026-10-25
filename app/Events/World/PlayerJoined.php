<?php

namespace App\Events\World;

use App\Domain\World\StoredPath;
use App\Events\WorldEvent;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;

final class PlayerJoined extends WorldEvent
{
    public function __construct(
        public readonly RoomInstance $instance,
        public readonly User $user,
        public readonly StoredPath $position,
    ) {
        parent::__construct();
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel($this->instance->channelName());
    }

    public function broadcastAs(): string
    {
        return 'PlayerJoined';
    }

    protected function payload(): array
    {
        return [
            'player' => [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'avatar' => $this->user->avatar?->toPayload(),
                ...$this->position->toArray(),
            ],
        ];
    }
}
