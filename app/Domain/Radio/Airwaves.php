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
 * What WHSH has on the air: at most one broadcast, heard through every set
 * in every room, from its start to its end on the server clock. Nothing is
 * pushed when a broadcast runs out; clients already know when it ends. Only
 * staff putting one on or taking it off are announced.
 */
final readonly class Airwaves
{
    /**
     * @return array{id: int, title: string, url: string, starts_at_ms: int, ends_at_ms: int}|null
     */
    public function payload(): ?array
    {
        return Broadcast::query()->onAir(now())->first()?->toPayload();
    }

    /** Tells everyone in every open room instance what is on the air now. */
    public function announce(): void
    {
        $payload = $this->payload();

        foreach (RoomInstance::query()->where('is_open', true)->pluck('id') as $instanceId) {
            WorldBroadcast::send(new BroadcastChanged($instanceId, $payload));
        }
    }

    /**
     * Changes what is on the air one at a time, so two staff can't both put
     * a broadcast on.
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
