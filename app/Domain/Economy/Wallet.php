<?php

namespace App\Domain\Economy;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Reads coin balances. A balance is always the sum of the user's ledger
 * entries; the cached copy is only for display and is dropped after every
 * write. Spending decisions use lockedBalance(), never the cache.
 */
final readonly class Wallet
{
    private const int CACHE_SECONDS = 600;

    public function __construct(private Cache $cache) {}

    public function balance(User $user): int
    {
        return (int) $this->cache->remember(self::cacheKey($user->id), self::CACHE_SECONDS, fn (): int => $this->sum($user->id));
    }

    /**
     * Locks the user's row and returns their balance straight from the ledger.
     * Must run inside a transaction; concurrent writers for the same user wait here.
     */
    public function lockedBalance(User $user): int
    {
        User::query()->whereKey($user->id)->lockForUpdate()->value('id');

        return $this->sum($user->id);
    }

    public function forget(int $userId): void
    {
        $this->cache->forget(self::cacheKey($userId));
    }

    private function sum(int $userId): int
    {
        return (int) LedgerEntry::query()->where('user_id', $userId)->sum('amount');
    }

    private static function cacheKey(int $userId): string
    {
        return "economy:balance:{$userId}";
    }
}
