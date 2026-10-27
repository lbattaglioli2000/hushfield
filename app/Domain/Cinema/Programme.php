<?php

namespace App\Domain\Cinema;

use App\Events\World\CinemaChanged;
use App\Events\WorldBroadcast;
use App\Features\WorldFlag;
use App\Models\Room;
use App\Models\Screening;

/**
 * What the VFA's screen shows: the film on now and those still to come, each
 * with its own start and end, so every client works out the same moment from
 * its server clock. Nothing is pushed when one film gives way to the next;
 * clients already have both. Only staff changes and the kill switch are announced.
 */
final readonly class Programme
{
    /** More than staff would queue in a day; the rest arrives with the next change or join. */
    private const int LIMIT = 50;

    public function isCinema(Room $room): bool
    {
        return $room->slug === config('hushfield.cinema.room');
    }

    /**
     * @return array{open: bool, screenings: list<array{id: int, video_id: string, title: string, starts_at_ms: int, ends_at_ms: int}>}
     */
    public function payload(): array
    {
        if (! WorldFlag::Cinema->isActive()) {
            return ['open' => false, 'screenings' => []];
        }

        return [
            'open' => true,
            'screenings' => array_values(Screening::query()->notOver(now())->limit(self::LIMIT)->get()
                ->map(fn (Screening $screening): array => $screening->toPayload())
                ->all()),
        ];
    }

    /** Tells everyone in every open instance of the cinema what the screen now shows. */
    public function announce(): void
    {
        $room = Room::query()->where('slug', config('hushfield.cinema.room'))->first();

        if ($room === null) {
            return;
        }

        $payload = $this->payload();

        foreach ($room->instances()->where('is_open', true)->pluck('id') as $instanceId) {
            WorldBroadcast::send(new CinemaChanged($instanceId, $payload));
        }
    }
}
