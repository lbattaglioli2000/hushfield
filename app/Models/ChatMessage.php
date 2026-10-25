<?php

namespace App\Models;

use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $room_instance_id
 * @property int $user_id
 * @property string $body
 * @property string $filtered_body
 * @property bool $was_filtered
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read RoomInstance $roomInstance
 */
#[Fillable(['room_instance_id', 'user_id', 'body', 'filtered_body', 'was_filtered'])]
class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory, MassPrunable;

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'was_filtered' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<RoomInstance, $this> */
    public function roomInstance(): BelongsTo
    {
        return $this->belongsTo(RoomInstance::class);
    }

    /** @return HasMany<ChatReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(ChatReport::class);
    }

    /**
     * Messages older than the retention window, except those a report points at.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('created_at', '<', now()->subDays((int) config('hushfield.chat.retention_days')))
            ->whereDoesntHave('reports');
    }
}
