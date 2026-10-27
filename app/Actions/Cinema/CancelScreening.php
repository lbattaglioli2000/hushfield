<?php

namespace App\Actions\Cinema;

use App\Domain\Audit\AuditLog;
use App\Domain\Cinema\Programme;
use App\Models\Screening;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Takes a film off its theater's programme. One showing now stops where it
 * is and the queue moves up behind an intermission; one still to come is
 * removed and the queued films after it move up.
 */
final readonly class CancelScreening
{
    public function __construct(private Programme $programme) {}

    /**
     * @return bool Whether anything changed: false for a film already over.
     */
    public function __invoke(Screening $screening, User $staff): bool
    {
        $stopped = $this->programme->change($screening->theater, function (CarbonInterface $now) use ($screening): ?bool {
            $screening->refresh();

            if ($screening->ends_at->lessThanOrEqualTo($now)) {
                return null;
            }

            if ($screening->isShowing($now)) {
                $screening->update(['ends_at' => $now]);

                return true;
            }

            $screening->delete();

            return false;
        });

        if ($stopped === null) {
            return false;
        }

        activity(AuditLog::Cinema)
            ->performedOn($screening)
            ->causedBy($staff)
            ->withProperties(['theater' => $screening->theater, 'video_id' => $screening->video_id, 'title' => $screening->title])
            ->event($stopped ? 'screening.stopped' : 'screening.removed')
            ->log(($stopped ? 'Stopped: ' : 'Removed from the programme: ').$screening->title);

        $this->programme->announce($screening->theater);

        return true;
    }
}
