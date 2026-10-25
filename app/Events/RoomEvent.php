<?php

namespace App\Events;

use App\Models\RoomInstance;
use Illuminate\Broadcasting\PresenceChannel;

/**
 * Sent to everyone in one room instance: presence-room.{instanceId}.
 */
abstract class RoomEvent extends WorldEvent
{
    public function __construct(public readonly int $instanceId)
    {
        parent::__construct();
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel(RoomInstance::channelNameFor($this->instanceId));
    }
}
