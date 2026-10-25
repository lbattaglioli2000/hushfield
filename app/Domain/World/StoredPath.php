<?php

namespace App\Domain\World;

/**
 * A walker's latest path as held in hf:pos:{userId}.
 */
final readonly class StoredPath
{
    /**
     * @param  list<array{int, int}>  $path
     */
    public function __construct(
        public array $path,
        public int $startedAtMs,
        public float $speed,
    ) {}

    public static function standingAt(int $x, int $y, int $nowMs): self
    {
        return new self([[$x, $y]], $nowMs, (float) config('hushfield.movement.speed_tiles_per_sec'));
    }

    public function positionAt(int $nowMs): PathPosition
    {
        return PathClock::positionAt($this->path, $this->startedAtMs, $this->speed, $nowMs);
    }

    /**
     * @return array{path: list<array{int, int}>, started_at_ms: int, speed: float}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'started_at_ms' => $this->startedAtMs,
            'speed' => $this->speed,
        ];
    }

    /**
     * @param  array{path: list<array{int, int}>, started_at_ms: int, speed: float|int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['path'], $data['started_at_ms'], (float) $data['speed']);
    }
}
