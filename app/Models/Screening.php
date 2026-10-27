<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One film on the VFA's programme (see App\Domain\Cinema\Programme). Everyone
 * in the room watches it from `starts_at` to `ends_at`; a film stopped early
 * ends when it was stopped.
 *
 * @property int $id
 * @property string $video_id A YouTube video id.
 * @property string $title
 * @property string|null $channel The YouTube channel it came from.
 * @property int $duration_seconds The whole film, however much of it is shown.
 * @property bool $made_for_kids As YouTube marks it.
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int|null $scheduled_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $scheduler
 */
#[Fillable(['video_id', 'title', 'channel', 'duration_seconds', 'made_for_kids', 'starts_at', 'ends_at', 'scheduled_by'])]
class Screening extends Model
{
    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'made_for_kids' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    /**
     * Showing now or still to come, in order.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notOver(Builder $query, CarbonInterface $now): void
    {
        $query->where('ends_at', '>', $now)->orderBy('starts_at');
    }

    public function isShowing(CarbonInterface $now): bool
    {
        return $this->starts_at->lessThanOrEqualTo($now) && $this->ends_at->greaterThan($now);
    }

    /**
     * @return array{id: int, video_id: string, title: string, starts_at_ms: int, ends_at_ms: int}
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'video_id' => $this->video_id,
            'title' => $this->title,
            'starts_at_ms' => $this->starts_at->getTimestampMs(),
            'ends_at_ms' => $this->ends_at->getTimestampMs(),
        ];
    }
}
