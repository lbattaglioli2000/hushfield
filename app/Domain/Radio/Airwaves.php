<?php

namespace App\Domain\Radio;

use App\Events\World\BroadcastChanged;
use App\Events\WorldBroadcast;
use App\Models\Broadcast;
use App\Models\RoomInstance;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What WHSH has on the air and coming up, heard through every set in every
 * room, each broadcast from its start to its end on the server clock. A
 * broadcast cuts short whatever is on when it starts, so at most one is ever
 * on. Nothing is pushed when one starts or runs out; clients already have
 * the lineup. Only staff changes are announced.
 */
final readonly class Airwaves
{
    /** More than staff would schedule ahead; the rest arrives with the next change or join. */
    private const int LIMIT = 20;

    /**
     * The broadcasts on the air or still to come at `$now`, in order, each
     * ending where the next one starts if that is sooner. Their `ends_at`
     * is set to that and must not be saved.
     *
     * @return list<Broadcast>
     */
    public function lineup(CarbonInterface $now): array
    {
        $broadcasts = Broadcast::query()->where('ends_at', '>', $now)->orderBy('starts_at')->limit(self::LIMIT)->with('scheduler')->get();
        $first = $broadcasts->first();

        if ($first === null) {
            return [];
        }

        // Every later start cuts short what was on, even one since taken off the air.
        $starts = Broadcast::query()->where('starts_at', '>', $first->starts_at)->orderBy('starts_at')->get(['starts_at'])->pluck('starts_at');

        foreach ($broadcasts as $broadcast) {
            $cut = $starts->first(fn (CarbonInterface $start): bool => $start->greaterThan($broadcast->starts_at));

            if ($cut !== null && $cut->lessThan($broadcast->ends_at)) {
                $broadcast->ends_at = $cut;
            }
        }

        return array_values($broadcasts->filter(fn (Broadcast $broadcast): bool => $broadcast->ends_at->greaterThan($now))->all());
    }

    public function onAir(CarbonInterface $now): ?Broadcast
    {
        $first = $this->lineup($now)[0] ?? null;

        return $first !== null && $first->starts_at->lessThanOrEqualTo($now) ? $first : null;
    }

    /**
     * @return list<array{id: int, title: string, url: string, starts_at_ms: int, ends_at_ms: int}>
     */
    public function payload(): array
    {
        return array_map(fn (Broadcast $broadcast): array => $broadcast->toPayload(), $this->lineup(now()));
    }

    /** Tells everyone in every open room instance what is on the air and coming up. */
    public function announce(): void
    {
        $payload = $this->payload();

        foreach (RoomInstance::query()->where('is_open', true)->pluck('id') as $instanceId) {
            WorldBroadcast::send(new BroadcastChanged($instanceId, $payload));
        }
    }

    /**
     * Changes the lineup one change at a time, so two staff can't take the
     * same slot.
     *
     * @template T
     *
     * @param  callable(CarbonInterface): T  $change  Given the moment the change is made.
     * @return T
     */
    public function change(callable $change): mixed
    {
        return Cache::lock('whsh-airwaves', 10)->block(5, fn () => DB::transaction(fn () => $change(now())));
    }
}
