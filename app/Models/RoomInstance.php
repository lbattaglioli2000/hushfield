<?php

namespace App\Models;

use App\Domain\World\RoomKind;
use Database\Factories\RoomInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $room_id
 * @property int|null $home_id The home this house or yard belongs to; null in a public room.
 * @property int $number
 * @property bool $is_open
 * @property Carbon|null $empty_since
 * @property-read Room $room
 * @property-read Home|null $home
 * @property-read Collection<int, HomeFurniture> $furniture
 */
#[Fillable(['number', 'home_id', 'is_open', 'empty_since'])]
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

    /** @return BelongsTo<Home, $this> */
    public function home(): BelongsTo
    {
        return $this->belongsTo(Home::class);
    }

    /** @return HasMany<HomeFurniture, $this> */
    public function furniture(): HasMany
    {
        return $this->hasMany(HomeFurniture::class);
    }

    /**
     * Every piece placed in this room of a home, as clients draw them.
     *
     * @return list<array{x: int, y: int, rotation: int, item: array<string, mixed>}>
     */
    public function furniturePayload(): array
    {
        return array_values($this->furniture()->with('inventoryItem.item')->orderBy('id')->get()
            ->map(fn (HomeFurniture $piece): array => $piece->toPayload())
            ->all());
    }

    /**
     * How players and staff see the instance: "Town Square (2)", or "Wren's residence" and "Wren's yard" for a home.
     */
    public function label(): string
    {
        if ($this->room->isResidence() && $this->home !== null) {
            $key = $this->room->kind === RoomKind::Yard ? 'hushfield.yard_label' : 'hushfield.home_label';

            return (string) __($key, ['name' => $this->home->owner->username]);
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
