<?php

namespace App\Events\Fishing;

use App\Events\RoomEvent;

/**
 * Someone in the room landed something rare. Everyone else sees one line about it.
 */
final class RareCatchLanded extends RoomEvent
{
    public function __construct(
        int $instanceId,
        public readonly int $userId,
        public readonly string $message,
    ) {
        parent::__construct($instanceId);
    }

    protected function payload(): array
    {
        return ['user_id' => $this->userId, 'message' => $this->message];
    }
}
