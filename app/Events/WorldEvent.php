<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Every world broadcast carries the protocol version and the server time it happened at,
 * so clients can detect outdated builds and order events against snapshots.
 */
abstract class WorldEvent implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public readonly int $atMs;

    public function __construct()
    {
        $this->atMs = (int) now()->getTimestampMs();
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /**
     * @return array<string, mixed>
     */
    final public function broadcastWith(): array
    {
        return [
            'protocol' => (int) config('hushfield.protocol'),
            'at_ms' => $this->atMs,
            ...$this->payload(),
        ];
    }
}
