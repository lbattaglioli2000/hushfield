<?php

namespace App\Events\Presence;

/**
 * Staff closed the player's instance or moved its occupants. The client rejoins,
 * preferring the target instance when one is given.
 */
final class InstanceReassigned extends SessionEvent
{
    public function __construct(string $sessionToken, public readonly ?int $targetInstanceId = null)
    {
        parent::__construct($sessionToken);
    }

    protected function payload(): array
    {
        return ['target_instance_id' => $this->targetInstanceId];
    }
}
