<?php

namespace App\Events\World;

use App\Events\RoomEvent;

/**
 * A home's furniture was placed, moved or put away. Carries the whole
 * arrangement, which clients draw instead of what they had.
 */
final class FurnitureChanged extends RoomEvent
{
    /**
     * @param  list<array{x: int, y: int, rotation: int, item: array<string, mixed>}>  $furniture
     */
    public function __construct(int $instanceId, public readonly array $furniture)
    {
        parent::__construct($instanceId);
    }

    protected function payload(): array
    {
        return ['furniture' => $this->furniture];
    }
}
