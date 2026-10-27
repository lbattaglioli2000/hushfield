<?php

namespace App\Actions\World;

use App\Domain\Audit\AuditLog;
use App\Domain\Cinema\Programme;
use App\Features\WorldFlag;
use App\Features\WorldFlags;
use App\Models\User;

/**
 * Turns a world kill switch on or off. Takes the state staff asked for rather
 * than toggling, so two staff turning the same flag off both leave it off.
 */
final readonly class SetWorldFlag
{
    public function __construct(private Programme $programme) {}

    /**
     * @return bool Whether the flag changed.
     */
    public function __invoke(WorldFlag $flag, bool $active, User $staff, string $reason): bool
    {
        if ($flag->isActive() === $active) {
            return false;
        }

        WorldFlags::set($flag->value, $active);

        activity(AuditLog::Flags)
            ->causedBy($staff)
            ->withProperties(['flag' => $flag->value, 'active' => $active, 'reason' => $reason])
            ->event($active ? 'flag.enabled' : 'flag.disabled')
            ->log($flag->getLabel().($active ? ' turned on' : ' turned off'));

        // The screen goes dark, or lights up, for everyone watching at once.
        if ($flag === WorldFlag::Cinema) {
            $this->programme->announceAll();
        }

        return true;
    }
}
