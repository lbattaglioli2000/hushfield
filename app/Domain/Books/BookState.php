<?php

namespace App\Domain\Books;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a book stands: being written, on the library's shelves, or withdrawn
 * from everywhere (copies stay with their holders, unreadable and untitled).
 */
enum BookState: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Published = 'published';
    case Withdrawn = 'withdrawn';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => 'success',
            self::Withdrawn => 'danger',
        };
    }
}
