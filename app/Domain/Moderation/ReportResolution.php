<?php

namespace App\Domain\Moderation;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ReportResolution: string implements HasColor, HasIcon, HasLabel
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

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The verb staff click to resolve a report this way.
     */
    public function verb(): string
    {
        return $this->sanctionType()?->verb() ?? 'Dismiss';
    }

    public function getColor(): string
    {
        return $this->sanctionType()?->getColor() ?? 'gray';
    }

    public function getIcon(): Heroicon
    {
        return $this->sanctionType()?->getIcon() ?? Heroicon::OutlinedXMark;
    }
}
