<?php

namespace App\Events\Presence;

/**
 * The player was moved out of their instance: staff closed it, or the home
 * they were visiting closed its door. The client shows the message and rejoins
 * the given room (its current one when null), preferring the target instance.
 */
final class InstanceReassigned extends SessionEvent
{
    public function __construct(
        string $sessionToken,
        public readonly string $message,
        public readonly ?int $targetInstanceId = null,
        public readonly ?string $roomSlug = null,
    ) {
        parent::__construct($sessionToken);
    }

    protected function payload(): array
    {
        return [
            'message' => $this->message,
            'target_instance_id' => $this->targetInstanceId,
            'room_slug' => $this->roomSlug,
        ];
    }
}
