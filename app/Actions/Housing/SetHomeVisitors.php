<?php

namespace App\Actions\Housing;

use App\Actions\World\RelocatePlayer;
use App\Domain\Housing\Residences;
use App\Domain\Presence\InstanceMembers;
use App\Exceptions\WorldActionRefused;
use App\Models\Home;
use App\Models\User;

/**
 * Opens or closes a resident's home to visitors. Closing it sends any visitors
 * inside back to the default room; the owner stays.
 */
final readonly class SetHomeVisitors
{
    public function __construct(
        private Residences $residences,
        private InstanceMembers $members,
        private RelocatePlayer $relocatePlayer,
    ) {}

    public function __invoke(User $owner, bool $open): Home
    {
        $residence = $this->residences->room() ?? throw WorldActionRefused::residencesClosed();
        $home = $this->residences->homeOf($owner, $residence);
        $home->forceFill(['is_open' => $open])->save();

        if (! $open) {
            $this->showVisitorsOut($home);
        }

        return $home;
    }

    /**
     * Everyone in the home except its owner leaves for the default room.
     */
    public function showVisitorsOut(Home $home): int
    {
        $visitors = User::query()
            ->with('worldSession')
            ->whereIn('id', $this->members->all($home->room_instance_id))
            ->whereKeyNot($home->user_id)
            ->get();

        foreach ($visitors as $visitor) {
            ($this->relocatePlayer)($visitor, $home->instance, (string) __('hushfield.home_door_closed'), roomSlug: (string) config('hushfield.world.default_room'));
        }

        return $visitors->count();
    }
}
