<?php

namespace App\Actions\Cinema;

use App\Domain\Audit\AuditLog;
use App\Domain\Cinema\Film;
use App\Domain\Cinema\FilmRefused;
use App\Domain\Cinema\Programme;
use App\Models\Screening;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Puts a film on a theater's programme: at the end of its queue, now
 * (cutting short whatever is showing, the queue moving back behind it), or
 * as a premiere at a time of staff's choosing, which the queue flows around.
 */
final readonly class ScheduleScreening
{
    public function __construct(private Programme $programme) {}

    /**
     * @param  CarbonInterface|null  $premiereAt  A premiere's start; null to queue it, or play it now with $playNow.
     *
     * @throws FilmRefused when a premiere is in the past or clashes with another.
     */
    public function __invoke(Film $film, string $theater, bool $playNow, User $staff, ?CarbonInterface $premiereAt = null): Screening
    {
        $screening = $this->programme->change($theater, function (CarbonInterface $now) use ($film, $theater, $playNow, $staff, $premiereAt): Screening {
            if ($premiereAt !== null) {
                self::ensureFree($theater, $premiereAt, $film->durationSeconds, $now);
                $startsAt = $premiereAt;
            } elseif ($playNow) {
                Screening::query()->inTheater($theater)->notOver($now)->where('starts_at', '<=', $now)->update(['ends_at' => $now]);
                $startsAt = $now;
            } else {
                // Its place is the end of the queue; the programme finds it its slot.
                $startsAt = $now->addYears(10);
            }

            return Screening::query()->create([
                'theater' => $theater,
                'premiere' => $premiereAt !== null,
                'video_id' => $film->videoId,
                'title' => $film->title,
                'channel' => $film->channel,
                'duration_seconds' => $film->durationSeconds,
                'made_for_kids' => $film->madeForKids,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addSeconds($film->durationSeconds),
                'scheduled_by' => $staff->id,
            ]);
        })->refresh();

        $event = match (true) {
            $premiereAt !== null => 'premiere',
            $playNow => 'played_now',
            default => 'queued',
        };

        activity(AuditLog::Cinema)
            ->performedOn($screening)
            ->causedBy($staff)
            ->withProperties(['theater' => $theater, 'video_id' => $film->videoId, 'title' => $film->title, 'starts_at' => $screening->starts_at->toIso8601String()])
            ->event("screening.{$event}")
            ->log(match ($event) {
                'premiere' => 'Premiere: ',
                'played_now' => 'Played now: ',
                default => 'Queued: ',
            }.$film->title);

        $this->programme->announce($theater);

        return $screening;
    }

    /**
     * A premiere needs its time to itself: in the future, and an intermission clear of any other premiere.
     *
     * @throws FilmRefused
     */
    private static function ensureFree(string $theater, CarbonInterface $at, int $seconds, CarbonInterface $now): void
    {
        if ($at->lessThanOrEqualTo($now)) {
            throw new FilmRefused('A premiere has to start in the future. To start it now, use Play now.');
        }

        $gap = (int) config('hushfield.cinema.intermission_seconds');
        $clash = Screening::query()->inTheater($theater)->notOver($now)->where('premiere', true)
            ->where('starts_at', '<', $at->addSeconds($seconds + $gap))
            ->where('ends_at', '>', $at->subSeconds($gap))
            ->first();

        if ($clash !== null) {
            throw new FilmRefused("It would run into the premiere of \"{$clash->title}\" at {$clash->starts_at->timezone(config('hushfield.timezone'))->format('g:i A')}.");
        }
    }
}
