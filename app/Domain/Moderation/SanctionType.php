<?php

namespace App\Domain\Moderation;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum SanctionType: string implements HasColor, HasIcon, HasLabel
{
    /** Chat is blocked; the player still sees their own messages. */
    case Mute = 'mute';

    /** World access is blocked. */
    case Ban = 'ban';

    /** A recorded reminder with no restriction. */
    case Warning = 'warning';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mute => 'Mute',
            self::Ban => 'Ban',
            self::Warning => 'Warning',
        };
    }

    /**
     * The verb staff click to issue it.
     */
    public function verb(): string
    {
        return match ($this) {
            self::Mute => 'Mute',
            self::Ban => 'Ban',
            self::Warning => 'Warn',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Mute => 'warning',
            self::Ban => 'danger',
            self::Warning => 'info',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Mute => Heroicon::OutlinedSpeakerXMark,
            self::Ban => Heroicon::OutlinedNoSymbol,
            self::Warning => Heroicon::OutlinedExclamationTriangle,
        };
    }
}
