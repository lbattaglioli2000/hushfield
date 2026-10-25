<?php

namespace App\Domain\Moderation;

enum ReportResolution: string
{
    case Dismissed = 'dismissed';
    case Warned = 'warned';
    case Muted = 'muted';
    case Banned = 'banned';

    public function sanctionType(): ?SanctionType
    {
        return match ($this) {
            self::Dismissed => null,
            self::Warned => SanctionType::Warning,
            self::Muted => SanctionType::Mute,
            self::Banned => SanctionType::Ban,
        };
    }
}
