<?php

namespace App\Domain\World;

use App\Models\Room;
use Illuminate\Redis\RedisManager;

/**
 * hf:pos:{userId}. Hot and reconstructible: a missing entry means "standing at the room's spawn".
 */
final class PositionStore
{
    public function __construct(private readonly RedisManager $redis) {}

    public function get(int $userId): ?StoredPath
    {
        return $this->decode($this->redis->connection('world')->get(self::key($userId)));
    }

    public function currentOrSpawn(int $userId, Room $room, int $nowMs): StoredPath
    {
        return $this->get($userId) ?? self::spawn($room, $nowMs);
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
        $raw = $this->redis->connection('world')->mget(array_map(self::key(...), $userIds));
        $paths = [];

        foreach ($userIds as $index => $userId) {
            $path = $this->decode($raw[$index] ?? null);

            if ($path !== null) {
                $paths[$userId] = $path;
            }
        }

        return $paths;
    }

    /**
     * Every given user's path, standing at the room's spawn when none is stored.
     *
     * @param  list<int>  $userIds
     * @return array<int, StoredPath>
     */
    public function manyOrSpawn(array $userIds, Room $room, int $nowMs): array
    {
        $paths = $this->many($userIds);

        foreach ($userIds as $userId) {
            $paths[$userId] ??= self::spawn($room, $nowMs);
        }

        return $paths;
    }

    public function put(int $userId, StoredPath $path): void
    {
        $this->redis->connection('world')->set(self::key($userId), json_encode($path->toArray(), JSON_THROW_ON_ERROR));
    }

    public function forget(int $userId): void
    {
        $this->redis->connection('world')->del(self::key($userId));
    }

    private static function key(int $userId): string
    {
        return "pos:{$userId}";
    }

    private static function spawn(Room $room, int $nowMs): StoredPath
    {
        return StoredPath::standingAt($room->spawn_x, $room->spawn_y, $nowMs);
    }

    private function decode(mixed $raw): ?StoredPath
    {
        if (! is_string($raw)) {
            return null;
        }

        /** @var array{path: list<array{int, int}>, started_at_ms: int, speed: float|int} $data */
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return StoredPath::fromArray($data);
    }
}
