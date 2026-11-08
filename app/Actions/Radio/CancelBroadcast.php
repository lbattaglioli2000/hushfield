<?php

namespace App\Actions\Radio;

use App\Domain\Audit\AuditLog;
use App\Domain\Radio\Airwaves;
use App\Models\Broadcast;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Takes a broadcast off WHSH's lineup, for every set at once: one on the air
 * stops where it is, and one still to come is removed.
 */
final readonly class CancelBroadcast
{
    public function __construct(private Airwaves $airwaves) {}

    /**
     * @return bool Whether anything changed: false for a broadcast already over.
     */
    public function __invoke(Broadcast $broadcast, User $staff): bool
    {
        $stopped = $this->airwaves->change(function (CarbonInterface $now) use ($broadcast): ?bool {
            $broadcast->refresh();

            if ($broadcast->starts_at->greaterThan($now)) {
                $broadcast->delete();

                return false;
            }

            if (! $this->airwaves->onAir($now)?->is($broadcast)) {
                return null;
            }

            $broadcast->update(['ends_at' => $now]);

            return true;
        });

        if ($stopped === null) {
            return false;
        }

        activity(AuditLog::Radio)
            ->performedOn($broadcast)
            ->causedBy($staff)
            ->withProperties(['title' => $broadcast->title, 'starts_at' => $broadcast->starts_at->toIso8601String()])
            ->event($stopped ? 'broadcast.stopped' : 'broadcast.removed')
            ->log(($stopped ? 'Taken off the air: ' : 'Removed from the schedule: ').$broadcast->title);

        $this->airwaves->announce();

        return true;
    }
}
