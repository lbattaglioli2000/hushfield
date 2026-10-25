<?php

namespace App\Models;

use App\Domain\Chat\BlockedTermList;
use App\Domain\Chat\BlockedTermMatchType;
use App\Domain\Chat\BlockedTermSeverity;
use Database\Factories\BlockedTermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $term
 * @property BlockedTermMatchType $match_type
 * @property BlockedTermSeverity $severity
 */
#[Fillable(['term', 'match_type', 'severity'])]
class BlockedTerm extends Model
{
    /** @use HasFactory<BlockedTermFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn () => app(BlockedTermList::class)->flush());
        static::deleted(fn () => app(BlockedTermList::class)->flush());
    }

    protected function casts(): array
    {
        return [
            'match_type' => BlockedTermMatchType::class,
            'severity' => BlockedTermSeverity::class,
        ];
    }
}
