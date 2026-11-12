<?php

namespace App\Events\World;

use App\Events\RoomEvent;

/**
 * The books on a bookshelf in a home changed. Carries only the shelf (the
 * owned piece): clients with it open fetch it again (BookshelfController).
 */
final class ShelfChanged extends RoomEvent
{
    public function __construct(int $instanceId, public readonly int $piece)
    {
        parent::__construct($instanceId);
    }

    protected function payload(): array
    {
        return ['piece' => $this->piece];
    }
}
