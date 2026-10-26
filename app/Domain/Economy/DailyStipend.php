<?php

namespace App\Domain\Economy;

use App\Domain\World\Clock;
use App\Models\LedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Hearthmore Group's daily stipend: one claim per resident per Hushfield day,
 * enforced by the ledger's idempotency key.
 */
final class DailyStipend
{
    public function amount(): int
    {
        return (int) config('hushfield.economy.daily_stipend');
    }

    public function keyFor(User $user): string
    {
        return "stipend:{$user->id}:{$this->today()->toDateString()}";
    }

    public function isAvailable(User $user): bool
    {
        return ! LedgerEntry::query()->where('idempotency_key', $this->keyFor($user))->exists();
    }

    /**
     * When the next Hushfield day starts.
     */
    public function nextAt(): CarbonImmutable
    {
        return $this->today()->addDay()->startOfDay();
    }

    private function today(): CarbonImmutable
    {
        return Clock::hushfieldNow();
    }
}
