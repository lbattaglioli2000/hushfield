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
use Illuminate\Support\Collection;

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
     * The message with up to $count lines on either side of it in the same instance, oldest first.
     *
     * @return Collection<int, ChatMessage>
     */
    public static function around(ChatMessage $message, int $count = 10): Collection
    {
        $base = fn () => self::query()
            ->with('user')
            ->where('room_instance_id', $message->room_instance_id);

        $before = $base()->where('id', '<', $message->id)->orderByDesc('id')->limit($count)->get()->reverse();
        $after = $base()->where('id', '>', $message->id)->orderBy('id')->limit($count)->get();

        return $before->toBase()
            ->push($message->loadMissing('user'))
            ->merge($after)
            ->values();
    }

    /**
     * What clients see of a line. The id is null for a muted player's unsaved line.
     *
     * @return array{id: int|null, user_id: int, body: string}
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'body' => $this->filtered_body,
        ];
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
