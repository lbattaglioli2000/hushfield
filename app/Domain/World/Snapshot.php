<?php

namespace App\Domain\World;

use App\Models\RoomInstance;

/**
 * Everything a client needs to draw an instance from scratch, including the
 * home and its furniture when the instance is someone's residence, and the
 * programme when it is the cinema.
 */
final readonly class Snapshot
{
    /**
     * @param  list<PlayerState>  $players
     * @param  array{open: bool, lights: string, screenings: list<array<string, mixed>>}|null  $cinema  See App\Domain\Cinema\Programme.
     */
    public function __construct(
        public RoomInstance $instance,
        public int $serverTimeMs,
        public array $players,
        public string $assetsVersion,
        public ?array $cinema = null,
    ) {}

    /**
     * @return array{protocol: int, server_time_ms: int, assets_version: string, instance: array<string, mixed>, room: array<string, mixed>, home: array<string, mixed>|null, furniture: list<array<string, mixed>>, cinema: array<string, mixed>|null, players: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        $room = $this->instance->room;
        $home = $room->isResidence() ? $this->instance->home : null;

        return [
            'protocol' => Protocol::VERSION,
            'server_time_ms' => $this->serverTimeMs,
            // The art this server draws with; a tab that booted with other art reloads.
            'assets_version' => $this->assetsVersion,
            'instance' => [
                'id' => $this->instance->id,
                'number' => $this->instance->number,
                'label' => $this->instance->label(),
                'channel' => $this->instance->channelName(),
            ],
            'room' => [
                'slug' => $room->slug,
                'name' => $room->name,
                'kind' => $room->kind->value,
                // Where the tuning dial points: homes, house and yard, share their room's home frequency.
                'frequency' => $home !== null ? $room->home_frequency : $room->frequency,
                'map_version' => $room->map_version,
                'map_url' => route('world.maps.show', ['room' => $room->slug, 'version' => $room->map_version]),
            ],
            'home' => $home?->toPayload(),
            'furniture' => $home !== null ? $this->instance->furniturePayload() : [],
            'cinema' => $this->cinema,
            'players' => array_map(fn (PlayerState $player): array => $player->toArray(), $this->players),
        ];
    }
}
