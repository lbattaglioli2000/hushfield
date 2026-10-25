<?php

namespace App\Actions\Economy;

use App\Domain\Audit\AuditLog;
use App\Domain\Economy\InsufficientCoins;
use App\Domain\Economy\LedgerReason;
use App\Exceptions\ModerationActionRefused;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Staff add coins to (positive amount) or remove coins from (negative amount)
 * a resident's balance, with a reason. Written to the ledger and the audit log.
 */
final readonly class AdjustCoins
{
    public function __construct(private RecordLedgerEntry $recordLedgerEntry) {}

    public function __invoke(User $target, int $amount, User $staff, string $reason): LedgerEntry
    {
        if ($amount === 0) {
            throw ModerationActionRefused::zeroAdjustment();
        }

        try {
            $entry = ($this->recordLedgerEntry)($target, $amount, LedgerReason::StaffAdjustment, 'staff:'.Str::uuid7(), actor: $staff, note: $reason);
        } catch (InsufficientCoins $refused) {
            throw ModerationActionRefused::insufficientCoins($refused->balance);
        }

        activity(AuditLog::Economy)
            ->performedOn($target)
            ->causedBy($staff)
            ->withProperties(['amount' => $amount, 'reason' => $reason, 'ledger_entry_id' => $entry->id])
            ->event($amount > 0 ? 'coins.granted' : 'coins.removed')
            ->log($amount > 0 ? "Granted {$amount} coins" : 'Removed '.(-$amount).' coins');

        return $entry;
    }
}
