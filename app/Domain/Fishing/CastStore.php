<?php

namespace App\Domain\Fishing;

use Illuminate\Redis\RedisManager;

/**
 * hf:fishing:cast:{userId}, each player's one line in the water, and
 * hf:fishing:reeled:{castId}, how each reel went, so a replay gets the same answer.
 * Both expire on their own; nothing here needs to outlive a minute or two.
 */
final class CastStore
{
    /** How long past its bite window a line stays in the water before it is tidied up. */
    private const int CAST_GRACE_SECONDS = 60;

    private const int RESULT_SECONDS = 600;

    public function __construct(private readonly RedisManager $redis) {}

    /**
     * Puts the line in the water, replacing any line already there.
     */
    public function put(int $userId, Cast $cast, int $nowMs): void
    {
        $ttl = (int) ceil(max(0, $cast->reelByMs - $nowMs) / 1000) + self::CAST_GRACE_SECONDS;

        $this->redis->connection('world')->setex(self::castKey($userId), $ttl, json_encode($cast->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * Takes the named line out of the water. Only one caller ever gets it:
     * null when it is not (or no longer) this player's line.
     */
    public function take(int $userId, string $castId): ?Cast
    {
        $connection = $this->redis->connection('world');
        $raw = $connection->get(self::castKey($userId));

        if (! is_string($raw)) {
            return null;
        }

        /** @var array{id: string, instance_id: int, bite_at_ms: int, reel_by_ms: int, catch: string} $data */
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        // Another line went in since, or a concurrent reel took this one first.
        if ($data['id'] !== $castId || (int) $connection->del(self::castKey($userId)) === 0) {
            return null;
        }

        return Cast::fromArray($data);
    }

    public function forget(int $userId): void
    {
        $this->redis->connection('world')->del(self::castKey($userId));
    }

    /**
     * @param  array{outcome: string, catch: string|null, paid: int}  $result
     */
    public function putResult(string $castId, array $result): void
    {
        $this->redis->connection('world')->setex(self::resultKey($castId), self::RESULT_SECONDS, json_encode($result, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{outcome: string, catch: string|null, paid: int}|null
     */
    public function result(string $castId): ?array
    {
        $raw = $this->redis->connection('world')->get(self::resultKey($castId));

        if (! is_string($raw)) {
            return null;
        }

        /** @var array{outcome: string, catch: string|null, paid: int} */
        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    private static function castKey(int $userId): string
    {
        return "fishing:cast:{$userId}";
    }

    private static function resultKey(string $castId): string
    {
        return "fishing:reeled:{$castId}";
    }
}
