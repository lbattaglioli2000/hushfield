<?php

namespace App\Models;

use App\Domain\Moderation\SanctionType;
use Database\Factories\SanctionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property SanctionType $type
 * @property string $reason
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $issued_by
 * @property int|null $chat_report_id
 * @property Carbon|null $lifted_at
 * @property int|null $lifted_by
 * @property-read User $user
 * @property-read User|null $issuer
 */
#[Fillable(['user_id', 'type', 'reason', 'starts_at', 'ends_at', 'issued_by', 'chat_report_id', 'lifted_at', 'lifted_by'])]
class Sanction extends Model
{
    /** @use HasFactory<SanctionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => SanctionType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('lifted_at')
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null
            && $this->starts_at->lte(now())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
