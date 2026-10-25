<?php

namespace App\Domain\World;

use Illuminate\Redis\RedisManager;

/**
 * hf:pos:{userId}. Hot and reconstructible: a missing entry means "standing at a spawn".
 */
final class PositionStore
{
    public function __construct(private readonly RedisManager $redis) {}

    public function get(int $userId): ?StoredPath
    {
        $raw = $this->redis->connection('world')->get("pos:{$userId}");

        if (! is_string($raw)) {
            return null;
        }

        /** @var array{path: list<array{int, int}>, started_at_ms: int, speed: float|int} $data */
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return StoredPath::fromArray($data);
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, StoredPath>
     */
    public function many(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var list<string|false|null> $raw */
        $raw = $this->redis->connection('world')->mget(array_map(fn (int $id): string => "pos:{$id}", $userIds));
        $paths = [];

        foreach ($userIds as $index => $userId) {
            if (is_string($raw[$index] ?? null)) {
                /** @var array{path: list<array{int, int}>, started_at_ms: int, speed: float|int} $data */
                $data = json_decode($raw[$index], true, flags: JSON_THROW_ON_ERROR);
                $paths[$userId] = StoredPath::fromArray($data);
            }
        }

        return $paths;
    }

    public function put(int $userId, StoredPath $path): void
    {
        $this->redis->connection('world')->set("pos:{$userId}", json_encode($path->toArray(), JSON_THROW_ON_ERROR));
    }

    public function forget(int $userId): void
    {
        $this->redis->connection('world')->del("pos:{$userId}");
    }
}
