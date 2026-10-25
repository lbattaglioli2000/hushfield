<?php

namespace App\Models;

use Database\Factories\RoomInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $room_id
 * @property int $number
 * @property bool $is_open
 * @property Carbon|null $empty_since
 * @property-read Room $room
 */
#[Fillable(['number', 'is_open', 'empty_since'])]
class RoomInstance extends Model
{
    /** @use HasFactory<RoomInstanceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'is_open' => 'boolean',
            'empty_since' => 'datetime',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return HasMany<ChatMessage, $this> */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function label(): string
    {
        return "{$this->room->name} ({$this->number})";
    }

    public function channelName(): string
    {
        return self::channelNameFor($this->id);
    }

    /**
     * The presence channel everyone in the instance shares (presence-room.{id} on the wire).
     */
    public static function channelNameFor(int $instanceId): string
    {
        return "room.{$instanceId}";
    }
}
