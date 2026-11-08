<?php

namespace App\Events\World;

use App\Events\RoomEvent;

/**
 * A resident put something on or took it off. Carries their whole look, which
 * clients draw instead of what they had.
 */
final class OutfitChanged extends RoomEvent
{
    /**
     * @param  array<string, mixed>  $avatar
     */
    public function __construct(int $instanceId, public readonly int $userId, public readonly array $avatar)
    {
        parent::__construct($instanceId);
    }

    protected function payload(): array
    {
        return ['user_id' => $this->userId, 'avatar' => $this->avatar];
    }
}
