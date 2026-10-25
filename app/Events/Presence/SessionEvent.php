<?php

namespace App\Events\Presence;

use App\Events\WorldEvent;
use App\Models\WorldSession;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Sent to one world session's private channel: private-world-session.{token}.
 */
abstract class SessionEvent extends WorldEvent
{
    public function __construct(public readonly string $sessionToken)
    {
        parent::__construct();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(WorldSession::channelNameFor($this->sessionToken));
    }

    protected function payload(): array
    {
        return [];
    }
}
