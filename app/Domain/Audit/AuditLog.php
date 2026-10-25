<?php

namespace App\Domain\Audit;

use Filament\Support\Contracts\HasLabel;

/**
 * The activity log names staff actions are written to.
 */
enum AuditLog: string implements HasLabel
{
    case Moderation = 'moderation';
    case Rooms = 'rooms';
    case Flags = 'flags';
    case Staff = 'staff';

    public function getLabel(): string
    {
        return match ($this) {
            self::Moderation => 'Moderation',
            self::Rooms => 'Rooms',
            self::Flags => 'Feature flags',
            self::Staff => 'Staff roles',
        };
    }
}
