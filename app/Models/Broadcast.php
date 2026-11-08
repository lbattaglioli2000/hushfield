<?php

namespace App\Models;

use App\Domain\Assets\ItemArt;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One MP3 on WHSH's lineup (see App\Domain\Radio\Airwaves), on the air now
 * or scheduled. Everyone hears it from `starts_at` to `ends_at`, unless
 * another broadcast starts first; one taken off the air early ends when it
 * was.
 *
 * @property int $id
 * @property string $title
 * @property string $path Its key on the public disk: broadcasts/{hash}.mp3.
 * @property int $bytes
 * @property int $duration_seconds The whole recording, however much of it airs.
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int|null $scheduled_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $scheduler
 */
#[Fillable(['title', 'path', 'bytes', 'duration_seconds', 'starts_at', 'ends_at', 'scheduled_by'])]
class Broadcast extends Model
{
    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'duration_seconds' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    /** Where browsers fetch the recording: the public bucket, or the public disk. */
    public function url(): string
    {
        return app(ItemArt::class)->url($this->path);
    }

    /**
     * @return array{id: int, title: string, url: string, starts_at_ms: int, ends_at_ms: int}
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url(),
            'starts_at_ms' => $this->starts_at->getTimestampMs(),
            'ends_at_ms' => $this->ends_at->getTimestampMs(),
        ];
    }
}
