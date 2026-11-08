<?php

namespace App\Events\World;

use App\Events\RoomEvent;

/**
 * Staff changed WHSH's lineup: a broadcast went on or off the air, or was
 * scheduled or removed. Sent to every open room instance, since every set
 * hears it; carries the whole lineup, which clients play instead of theirs.
 */
final class BroadcastChanged extends RoomEvent
{
    /**
     * @param  list<array{id: int, title: string, url: string, starts_at_ms: int, ends_at_ms: int}>  $broadcasts
     */
    public function __construct(int $instanceId, public readonly array $broadcasts)
    {
        parent::__construct($instanceId);
    }

    protected function payload(): array
    {
        return ['broadcasts' => $this->broadcasts];
    }
}
