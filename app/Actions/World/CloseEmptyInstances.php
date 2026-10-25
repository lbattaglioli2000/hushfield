<?php

namespace App\Actions\World;

use App\Domain\Presence\InstanceMembers;
use App\Domain\World\InstanceAllocator;
use App\Models\RoomInstance;
use Illuminate\Database\Eloquent\Collection;

/**
 * Closes instances above number 1 that have been empty for a while.
 *
 * Each room is checked under its join lock so a join cannot land in an instance
 * while it is being closed. A room whose lock is busy is left for the next run.
 */
final readonly class CloseEmptyInstances
{
    public function __construct(
        private InstanceMembers $members,
        private InstanceAllocator $allocator,
    ) {}

    public function __invoke(): int
    {
        $cutoff = now()->subMinutes((int) config('hushfield.world.close_empty_instances_after_minutes'));

        return RoomInstance::query()
            ->where('is_open', true)
            ->where('number', '>', 1)
            ->where('empty_since', '<=', $cutoff)
            ->get()
            ->groupBy('room_id')
            ->sum(fn (Collection $instances, int $roomId): int => $this->closeEmpty($roomId, $instances));
    }

    /**
     * @param  Collection<int, RoomInstance>  $instances
     */
    private function closeEmpty(int $roomId, Collection $instances): int
    {
        $lock = $this->allocator->lockFor($roomId);

        if (! $lock->get()) {
            return 0;
        }

        try {
            $counts = $this->members->countsFor($instances);
            $empty = $instances->filter(fn (RoomInstance $instance): bool => $counts[$instance->id] === 0);

            if ($empty->isNotEmpty()) {
                RoomInstance::query()->whereKey($empty->modelKeys())->update(['is_open' => false]);
            }

            return $empty->count();
        } finally {
            $lock->release();
        }
    }
}
