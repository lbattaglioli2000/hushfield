<?php

namespace App\Domain\Cinema;

use App\Events\World\CinemaChanged;
use App\Events\WorldBroadcast;
use App\Features\WorldFlag;
use App\Models\Room;
use App\Models\Screening;
use App\Models\Theater;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What each of the VFA's theaters shows: the film on now and those still to
 * come, each with its own start and end, so every client works out the same
 * moment from its server clock, and how its house lights are set. Nothing is
 * pushed when one film gives way to the next; clients already have both.
 * Only staff changes and the kill switch are announced.
 *
 * A theater's programme is premieres, which start at their own time and never
 * move, and a queue of films, which run one after another an intermission
 * apart in the gaps the premieres leave. A premiere cuts short whatever is
 * still showing when it starts.
 */
final readonly class Programme
{
    /** More than staff would queue in a day; the rest arrives with the next change or join. */
    private const int LIMIT = 50;

    /**
     * Every theater, room slug => name.
     *
     * @return array<string, string>
     */
    public function theaters(): array
    {
        return (array) config('hushfield.cinema.theaters');
    }

    public function isTheater(Room $room): bool
    {
        return array_key_exists($room->slug, $this->theaters());
    }

    /**
     * @return array{open: bool, lights: string, screenings: list<array{id: int, video_id: string, title: string, premiere: bool, starts_at_ms: int, ends_at_ms: int}>}
     */
    public function payload(string $theater): array
    {
        $lights = Theater::lights($theater)->value;

        if (! WorldFlag::Cinema->isActive()) {
            return ['open' => false, 'lights' => $lights, 'screenings' => []];
        }

        return [
            'open' => true,
            'lights' => $lights,
            'screenings' => array_values(Screening::query()->inTheater($theater)->notOver(now())->limit(self::LIMIT)->get()
                ->map(fn (Screening $screening): array => $screening->toPayload())
                ->all()),
        ];
    }

    /** Tells everyone in every open instance of the theater what it now shows. */
    public function announce(string $theater): void
    {
        $room = Room::query()->where('slug', $theater)->first();

        if ($room === null) {
            return;
        }

        $payload = $this->payload($theater);

        foreach ($room->instances()->where('is_open', true)->pluck('id') as $instanceId) {
            WorldBroadcast::send(new CinemaChanged($instanceId, $payload));
        }
    }

    public function announceAll(): void
    {
        foreach (array_keys($this->theaters()) as $theater) {
            $this->announce($theater);
        }
    }

    /**
     * Changes a theater's programme one at a time, so two staff can't take
     * the same slot, then lays its queue out again.
     *
     * @template T
     *
     * @param  callable(CarbonInterface): T  $change  Given the moment the change is made.
     * @return T
     */
    public function change(string $theater, callable $change): mixed
    {
        return Cache::lock("cinema-programme:{$theater}", 10)->block(5, fn () => DB::transaction(function () use ($theater, $change) {
            $now = now();
            $result = $change($now);
            $this->arrange($theater, $now);

            return $result;
        }));
    }

    /**
     * Lays the theater's queue out from where the screen is free: after the
     * film showing, or an intermission after the last one ended, or now. Each
     * queued film keeps its place in the queue and takes the first gap a
     * premiere leaves for it, an intermission clear on either side. A film
     * showing when a premiere is due ends as it starts.
     */
    private function arrange(string $theater, CarbonInterface $now): void
    {
        $gap = (int) config('hushfield.cinema.intermission_seconds');
        $programme = Screening::query()->inTheater($theater)->notOver($now)->get();
        $showing = $programme->first(fn (Screening $screening): bool => $screening->isShowing($now));
        $premieres = $programme->filter(fn (Screening $screening): bool => $screening->premiere && ! $screening->is($showing))->values();
        $queued = $programme->reject(fn (Screening $screening): bool => $screening->premiere || $screening->is($showing));

        if ($showing !== null && ! $showing->premiere) {
            $due = $premieres->first(fn (Screening $premiere): bool => $premiere->starts_at->lessThan($showing->ends_at));

            if ($due !== null) {
                $showing->update(['ends_at' => $due->starts_at]);
            }
        }

        $lastEnded = Screening::query()->inTheater($theater)->where('ends_at', '<=', $now)->max('ends_at');
        $from = match (true) {
            $showing !== null => $showing->ends_at->addSeconds($gap),
            $lastEnded !== null => max($now, $now->parse($lastEnded)->addSeconds($gap)),
            default => $now,
        };

        foreach ($queued as $screening) {
            $start = $from;

            // Past every premiere it would run into, until it fits.
            do {
                $clash = $premieres->first(fn (Screening $premiere): bool => $start->lessThan($premiere->ends_at->addSeconds($gap))
                    && $start->addSeconds($screening->duration_seconds + $gap)->greaterThan($premiere->starts_at));

                if ($clash !== null) {
                    $start = $clash->ends_at->addSeconds($gap);
                }
            } while ($clash !== null);

            $screening->update(['starts_at' => $start, 'ends_at' => $start->addSeconds($screening->duration_seconds)]);
            $from = $screening->ends_at->addSeconds($gap);
        }
    }
}
