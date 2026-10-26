<?php

namespace App\Actions\Fishing;

use App\Actions\Economy\RecordLedgerEntry;
use App\Domain\Economy\LedgerReason;
use App\Domain\Economy\Wallet;
use App\Domain\Fishing\Cast;
use App\Domain\Fishing\CastStore;
use App\Domain\Fishing\CatchTable;
use App\Domain\Fishing\FishingCatch;
use App\Domain\Fishing\ReelOutcome;
use App\Domain\Fishing\ReelResult;
use App\Domain\World\Clock;
use App\Events\Fishing\RareCatchLanded;
use App\Events\WorldBroadcast;
use App\Exceptions\WorldActionRefused;
use App\Features\WorldFlag;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reels a line in, judged on the server's clock: inside the bite window it
 * lands the catch rolled at the cast and pays its coins, up to the daily
 * fishing cap; before or after, it gets away. Each line is reeled once. A
 * replay of the same reel gets the same answer and pays nothing more; the
 * ledger's idempotency key per cast backs that up.
 */
final readonly class ReelIn
{
    public function __construct(
        private CastStore $casts,
        private CatchTable $catches,
        private Wallet $wallet,
        private RecordLedgerEntry $recordLedgerEntry,
    ) {}

    public function __invoke(User $user, string $castId): ReelResult
    {
        WorldFlag::Fishing->ensureActive();

        $reeledAt = Clock::nowMs();
        $cast = $this->casts->take($user->id, $castId);

        if ($cast === null) {
            return $this->replay($castId) ?? throw WorldActionRefused::fishingNoLine();
        }

        $outcome = $cast->judge($reeledAt);
        $catch = $outcome === ReelOutcome::Landed ? $this->catches->find($cast->catchKey) : null;
        $result = new ReelResult($outcome, $catch, $catch === null ? 0 : $this->pay($user, $cast, $catch));

        $this->casts->putResult($cast->id, ['outcome' => $outcome->value, 'catch' => $catch?->key, 'paid' => $result->paid]);

        if ($catch?->isAnnounced()) {
            WorldBroadcast::toOthers(new RareCatchLanded($cast->instanceId, $user->id, (string) __('hushfield.fishing_rare_landed', [
                'name' => $user->username,
                'catch' => lcfirst($catch->name()),
            ])));
        }

        return $result;
    }

    /**
     * Credits the catch, or as much of it as today's cap allows. The cap is read
     * under the user's lock, so two reels at once cannot both slip under it.
     */
    private function pay(User $user, Cast $cast, FishingCatch $catch): int
    {
        return DB::transaction(function () use ($user, $cast, $catch): int {
            $this->wallet->lockedBalance($user);

            $startOfDay = Clock::hushfieldNow()->startOfDay()->utc();
            $paidToday = (int) LedgerEntry::query()
                ->where('user_id', $user->id)
                ->where('reason', LedgerReason::Fishing)
                ->where('created_at', '>=', $startOfDay)
                ->sum('amount');
            $amount = max(0, min($catch->coins, (int) config('hushfield.fishing.daily_cap') - $paidToday));

            if ($amount === 0) {
                return 0;
            }

            return ($this->recordLedgerEntry)($user, $amount, LedgerReason::Fishing, "fishing:{$cast->id}", note: $catch->name())->amount;
        });
    }

    private function replay(string $castId): ?ReelResult
    {
        $stored = $this->casts->result($castId);

        if ($stored === null) {
            return null;
        }

        return new ReelResult(
            ReelOutcome::from($stored['outcome']),
            $stored['catch'] === null ? null : $this->catches->find($stored['catch']),
            $stored['paid'],
        );
    }
}
