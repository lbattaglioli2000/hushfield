<?php

namespace App\Events\World;

use App\Events\RoomEvent;

/**
 * A VFA theater's programme or house lights changed, or the screens were switched on or off. Carries
 * the whole programme, which clients play instead of what they had.
 */
final class CinemaChanged extends RoomEvent
{
    /**
     * @param  array{open: bool, lights: string, screenings: list<array<string, mixed>>}  $cinema
     */
    public function __construct(int $instanceId, public readonly array $cinema)
    {
        parent::__construct($instanceId);
    }

    protected function payload(): array
    {
        return ['cinema' => $this->cinema];
    }
}
