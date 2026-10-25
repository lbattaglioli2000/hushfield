<?php

namespace App\Actions\World;

use App\Domain\Presence\InstanceMembers;
use App\Models\RoomInstance;

/**
 * Closes instances above number 1 that have been empty for a while.
 */
final readonly class CloseEmptyInstances
{
    public function __construct(private InstanceMembers $members) {}

    public function __invoke(): int
    {
        $cutoff = now()->subMinutes((int) config('hushfield.world.close_empty_instances_after_minutes'));

        return RoomInstance::query()
            ->where('is_open', true)
            ->where('number', '>', 1)
            ->where('empty_since', '<=', $cutoff)
            ->get()
            ->filter(fn (RoomInstance $instance): bool => $this->members->count($instance->id) === 0)
            ->each(fn (RoomInstance $instance) => $instance->forceFill(['is_open' => false])->save())
            ->count();
    }
}
