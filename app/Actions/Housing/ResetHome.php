<?php

namespace App\Actions\Housing;

use App\Domain\Audit\AuditLog;
use App\Domain\Housing\HomeVisitors;
use App\Events\World\FurnitureChanged;
use App\Events\WorldBroadcast;
use App\Models\Home;
use App\Models\User;

/**
 * Staff put away every piece of furniture in a home and close it to visitors,
 * for an arrangement that breaks the rules. The owner keeps their items. Audited.
 */
final readonly class ResetHome
{
    public function __construct(private SetHomeVisitors $setHomeVisitors) {}

    public function __invoke(Home $home, User $staff, string $reason): void
    {
        $removed = $home->furniture()->delete();
        $home->forceFill(['visitors' => HomeVisitors::Closed])->save();
        $shownOut = $this->setHomeVisitors->showOutUnwelcome($home);

        WorldBroadcast::send(new FurnitureChanged($home->room_instance_id, []));

        activity(AuditLog::Moderation)
            ->performedOn($home->owner)
            ->causedBy($staff)
            ->withProperties(['home_id' => $home->id, 'furniture_removed' => $removed, 'visitors_shown_out' => $shownOut, 'reason' => $reason])
            ->event('home.reset')
            ->log('Reset residence');
    }
}
