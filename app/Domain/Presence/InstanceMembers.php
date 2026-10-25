<?php

namespace App\Domain\Presence;

use Illuminate\Redis\RedisManager;

/**
 * hf:instance:{instanceId}:members, the authoritative occupancy of each room instance.
 */
final class InstanceMembers
{
    public function __construct(private readonly RedisManager $redis) {}

    public function add(int $instanceId, int $userId): void
    {
        $this->redis->connection('world')->sadd(self::key($instanceId), $userId);
    }

    public function remove(int $instanceId, int $userId): void
    {
        $this->redis->connection('world')->srem(self::key($instanceId), $userId);
    }

    public function contains(int $instanceId, int $userId): bool
    {
        return (bool) $this->redis->connection('world')->sismember(self::key($instanceId), $userId);
    }

    public function count(int $instanceId): int
    {
        return (int) $this->redis->connection('world')->scard(self::key($instanceId));
    }

    /**
     * @param  list<int>  $instanceIds
     * @return array<int, int>
     */
    public function counts(array $instanceIds): array
    {
        $connection = $this->redis->connection('world');
        $counts = [];

        foreach ($instanceIds as $instanceId) {
            $counts[$instanceId] = (int) $connection->scard(self::key($instanceId));
        }

        return $counts;
    }

    /**
     * @return list<int>
     */
    public function all(int $instanceId): array
    {
        /** @var list<string|int> $members */
        $members = $this->redis->connection('world')->smembers(self::key($instanceId));

        return array_values(array_map(intval(...), $members));
    }

    public static function key(int $instanceId): string
    {
        return "instance:{$instanceId}:members";
    }
}
