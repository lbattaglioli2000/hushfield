<?php

namespace App\Actions\Radio;

use App\Domain\Audit\AuditLog;
use App\Domain\Radio\Airwaves;
use App\Models\Broadcast;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Takes a broadcast off WHSH's air where it is, for every set at once.
 */
final readonly class StopBroadcast
{
    public function __construct(private Airwaves $airwaves) {}

    /**
     * @return bool Whether anything changed: false for a broadcast already over.
     */
    public function __invoke(Broadcast $broadcast, User $staff): bool
    {
        $stopped = $this->airwaves->change(function (CarbonInterface $now) use ($broadcast): bool {
            $broadcast->refresh();

            if ($broadcast->ends_at->lessThanOrEqualTo($now)) {
                return false;
            }

            $broadcast->update(['ends_at' => $now]);

            return true;
        });

        if (! $stopped) {
            return false;
        }

        activity(AuditLog::Radio)
            ->performedOn($broadcast)
            ->causedBy($staff)
            ->withProperties(['title' => $broadcast->title])
            ->event('broadcast.stopped')
            ->log("Taken off the air: {$broadcast->title}");

        $this->airwaves->announce();

        return true;
    }
}
