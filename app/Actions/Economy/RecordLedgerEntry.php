<?php

namespace App\Actions\Economy;

use App\Domain\Economy\InsufficientCoins;
use App\Domain\Economy\LedgerReason;
use App\Domain\Economy\Wallet;
use App\Models\Item;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only way coins move. Idempotent on the key: repeating a write returns
 * the original entry (with wasRecentlyCreated false) instead of writing again.
 * Debits never take a balance below zero.
 */
final readonly class RecordLedgerEntry
{
    public function __construct(private Wallet $wallet) {}

    /**
     * @throws InsufficientCoins
     */
    public function __invoke(User $user, int $amount, LedgerReason $reason, string $idempotencyKey, ?Item $item = null, ?User $actor = null, ?string $note = null): LedgerEntry
    {
        return DB::transaction(function () use ($user, $amount, $reason, $idempotencyKey, $item, $actor, $note): LedgerEntry {
            // Taking the user's lock first serializes every write for this user, including the replay check.
            $balance = $this->wallet->lockedBalance($user);

            $existing = LedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($amount < 0 && $balance + $amount < 0) {
                throw new InsufficientCoins($balance, -$amount);
            }

            $entry = LedgerEntry::query()->create([
                'user_id' => $user->id,
                'amount' => $amount,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'item_id' => $item?->id,
                'actor_id' => $actor?->id,
                'note' => $note,
            ]);

            DB::afterCommit(fn () => $this->wallet->forget($user->id));

            return $entry;
        });
    }
}
