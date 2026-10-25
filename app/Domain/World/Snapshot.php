<?php

namespace App\Domain\World;

use App\Models\RoomInstance;

/**
 * Everything a client needs to draw an instance from scratch.
 */
final readonly class Snapshot
{
    /**
     * @param  list<PlayerState>  $players
     */
    public function __construct(
        public RoomInstance $instance,
        public int $serverTimeMs,
        public array $players,
    ) {}

    /**
     * @return array{protocol: int, server_time_ms: int, instance: array<string, mixed>, room: array<string, mixed>, players: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        $room = $this->instance->room;

        return [
            'protocol' => Protocol::VERSION,
            'server_time_ms' => $this->serverTimeMs,
            'instance' => [
                'id' => $this->instance->id,
                'number' => $this->instance->number,
                'label' => $this->instance->label(),
                'channel' => $this->instance->channelName(),
            ],
            'room' => [
                'slug' => $room->slug,
                'name' => $room->name,
                'map_version' => $room->map_version,
                'map_url' => route('world.maps.show', ['room' => $room->slug, 'version' => $room->map_version]),
            ],
            'players' => array_map(fn (PlayerState $player): array => $player->toArray(), $this->players),
        ];
    }
}
