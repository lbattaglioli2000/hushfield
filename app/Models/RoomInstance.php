<?php

namespace App\Models;

use Database\Factories\RoomInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $room_id
 * @property int $number
 * @property bool $is_open
 * @property Carbon|null $empty_since
 * @property-read Room $room
 * @property-read Home|null $home
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

    /** @return HasOne<Home, $this> */
    public function home(): HasOne
    {
        return $this->hasOne(Home::class);
    }

    /**
     * How players and staff see the instance: "Town Square (2)", or "Wren's residence" for a home.
     */
    public function label(): string
    {
        if ($this->room->isResidence() && $this->home !== null) {
            return (string) __('hushfield.home_label', ['name' => $this->home->owner->username]);
        }

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
