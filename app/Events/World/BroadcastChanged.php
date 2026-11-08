<?php

namespace App\Events\World;

use App\Events\RoomEvent;

/**
 * WHSH went on or off the air. Sent to every open room instance, since every
 * set hears it; carries what is on now, null for nothing.
 */
final class BroadcastChanged extends RoomEvent
{
    /**
     * @param  array{id: int, title: string, url: string, starts_at_ms: int, ends_at_ms: int}|null  $broadcast
     */
    public function __construct(int $instanceId, public readonly ?array $broadcast)
    {
        parent::__construct($instanceId);
    }

    protected function payload(): array
    {
        return ['broadcast' => $this->broadcast];
    }
}
