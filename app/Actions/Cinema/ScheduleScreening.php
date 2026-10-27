<?php

namespace App\Actions\Cinema;

use App\Domain\Audit\AuditLog;
use App\Domain\Cinema\Film;
use App\Domain\Cinema\Programme;
use App\Models\Screening;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Puts a film on the VFA's programme: at the end of the queue, after an
 * intermission (at once if the screen is empty), or now, cutting short
 * whatever is showing and moving the queue back behind it.
 */
final readonly class ScheduleScreening
{
    public function __construct(private Programme $programme) {}

    public function __invoke(Film $film, bool $playNow, User $staff): Screening
    {
        // Two staff queueing at once must not both take the same slot.
        $screening = Cache::lock('cinema-programme', 10)->block(5, fn (): Screening => DB::transaction(function () use ($film, $playNow, $staff): Screening {
            $now = now();
            $programme = Screening::query()->notOver($now)->get();
            $showing = $programme->first(fn (Screening $screening): bool => $screening->isShowing($now));

            if ($playNow) {
                $showing?->update(['ends_at' => $now]);
                $startsAt = $now;
            } else {
                $last = $programme->last();
                $startsAt = $last === null ? $now : $last->ends_at->copy()->addSeconds(self::intermission());
            }

            $screening = Screening::query()->create([
                'video_id' => $film->videoId,
                'title' => $film->title,
                'channel' => $film->channel,
                'duration_seconds' => $film->durationSeconds,
                'made_for_kids' => $film->madeForKids,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addSeconds($film->durationSeconds),
                'scheduled_by' => $staff->id,
            ]);

            if ($playNow) {
                self::reflow($programme->reject(fn (Screening $queued): bool => $queued->is($showing)), $screening->ends_at->copy()->addSeconds(self::intermission()));
            }

            return $screening;
        }));

        activity(AuditLog::Cinema)
            ->performedOn($screening)
            ->causedBy($staff)
            ->withProperties(['video_id' => $film->videoId, 'title' => $film->title, 'starts_at' => $screening->starts_at->toIso8601String()])
            ->event($playNow ? 'screening.played_now' : 'screening.queued')
            ->log(($playNow ? 'Played now: ' : 'Queued: ').$film->title);

        $this->programme->announce();

        return $screening;
    }

    /**
     * Runs the queued films back to back from $from, an intermission apart, in their order.
     *
     * @param  iterable<Screening>  $queued
     */
    public static function reflow(iterable $queued, CarbonInterface $from): void
    {
        foreach ($queued as $screening) {
            $screening->update(['starts_at' => $from, 'ends_at' => $from->copy()->addSeconds($screening->duration_seconds)]);
            $from = $screening->ends_at->copy()->addSeconds(self::intermission());
        }
    }

    private static function intermission(): int
    {
        return (int) config('hushfield.cinema.intermission_seconds');
    }
}
