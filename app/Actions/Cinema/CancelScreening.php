<?php

namespace App\Actions\Cinema;

use App\Domain\Audit\AuditLog;
use App\Domain\Cinema\Programme;
use App\Models\Screening;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Takes a film off the VFA's programme. One showing now stops where it is
 * and the queue moves up behind an intermission; one still to come is
 * removed and the films after it move up into its slot.
 */
final readonly class CancelScreening
{
    public function __construct(private Programme $programme) {}

    /**
     * @return bool Whether anything changed: false for a film already over.
     */
    public function __invoke(Screening $screening, User $staff): bool
    {
        $stopped = Cache::lock('cinema-programme', 10)->block(5, fn (): ?bool => DB::transaction(function () use ($screening): ?bool {
            $now = now();
            $screening->refresh();

            if ($screening->ends_at->lessThanOrEqualTo($now)) {
                return null;
            }

            $after = Screening::query()->notOver($now)->where('starts_at', '>', $screening->starts_at)->get();

            if ($screening->isShowing($now)) {
                $screening->update(['ends_at' => $now]);
                ScheduleScreening::reflow($after, $now->copy()->addSeconds((int) config('hushfield.cinema.intermission_seconds')));

                return true;
            }

            $slot = $screening->starts_at->copy();
            $screening->delete();
            ScheduleScreening::reflow($after, $slot);

            return false;
        }));

        if ($stopped === null) {
            return false;
        }

        activity(AuditLog::Cinema)
            ->performedOn($screening)
            ->causedBy($staff)
            ->withProperties(['video_id' => $screening->video_id, 'title' => $screening->title])
            ->event($stopped ? 'screening.stopped' : 'screening.removed')
            ->log(($stopped ? 'Stopped: ' : 'Removed from the queue: ').$screening->title);

        $this->programme->announce();

        return true;
    }
}
