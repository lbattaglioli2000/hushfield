<?php

namespace App\Domain\Identity;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StaffRole: string implements HasColor, HasLabel
{
    case Admin = 'admin';
    case Moderator = 'moderator';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'primary',
            self::Moderator => 'info',
        };
    }
}
