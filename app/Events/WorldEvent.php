<?php

namespace App\Events;

use App\Domain\World\Clock;
use App\Domain\World\Protocol;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Every world broadcast carries the protocol version and the server time it happened at,
 * so clients can detect outdated builds and order events against snapshots.
 * The client listens for each event by its class name.
 */
abstract class WorldEvent implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public readonly int $atMs;

    public function __construct()
    {
        $this->atMs = Clock::nowMs();
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    public function broadcastAs(): string
    {
        return class_basename(static::class);
    }

    /**
     * @return array<string, mixed>
     */
    final public function broadcastWith(): array
    {
        return [
            'protocol' => Protocol::VERSION,
            'at_ms' => $this->atMs,
            ...$this->payload(),
        ];
    }
}
