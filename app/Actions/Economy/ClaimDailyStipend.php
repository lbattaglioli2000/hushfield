<?php

namespace App\Actions\Economy;

use App\Domain\Economy\DailyStipend;
use App\Domain\Economy\LedgerReason;
use App\Exceptions\WorldActionRefused;
use App\Models\LedgerEntry;
use App\Models\User;

/**
 * Credits Hearthmore Group's daily stipend, once per resident per Hushfield day.
 */
final readonly class ClaimDailyStipend
{
    public function __construct(
        private DailyStipend $stipend,
        private RecordLedgerEntry $recordLedgerEntry,
    ) {}

    public function __invoke(User $user): LedgerEntry
    {
        $entry = ($this->recordLedgerEntry)($user, $this->stipend->amount(), LedgerReason::Stipend, $this->stipend->keyFor($user));

        if (! $entry->wasRecentlyCreated) {
            throw WorldActionRefused::stipendClaimed();
        }

        return $entry;
    }
}
