<?php

namespace App\Actions\Housing;

use App\Actions\World\RelocatePlayer;
use App\Domain\Housing\HomeVisitors;
use App\Domain\Housing\Residences;
use App\Domain\Presence\InstanceMembers;
use App\Exceptions\WorldActionRefused;
use App\Models\Home;
use App\Models\User;

/**
 * Sets who may visit a resident's home. Visitors inside who are no longer
 * welcome are sent back to the default room; the owner stays.
 */
final readonly class SetHomeVisitors
{
    public function __construct(
        private Residences $residences,
        private InstanceMembers $members,
        private RelocatePlayer $relocatePlayer,
    ) {}

    public function __invoke(User $owner, HomeVisitors $visitors): Home
    {
        $residence = $this->residences->room() ?? throw WorldActionRefused::residencesClosed();
        $home = $this->residences->homeOf($owner, $residence);
        $home->forceFill(['visitors' => $visitors])->save();

        $this->showOutUnwelcome($home);

        return $home;
    }

    /**
     * Everyone in the home, house or yard, whom it no longer admits leaves for the default room.
     */
    public function showOutUnwelcome(Home $home): int
    {
        $shownOut = 0;

        foreach ($home->instances as $instance) {
            $unwelcome = User::query()
                ->with('worldSession')
                ->whereIn('id', $this->members->all($instance->id))
                ->whereKeyNot($home->user_id)
                ->get()
                ->reject(fn (User $visitor): bool => $home->admits($visitor));

            foreach ($unwelcome as $visitor) {
                ($this->relocatePlayer)($visitor, $instance, (string) __('hushfield.home_door_closed'), roomSlug: (string) config('hushfield.world.default_room'));
            }

            $shownOut += $unwelcome->count();
        }

        return $shownOut;
    }
}
