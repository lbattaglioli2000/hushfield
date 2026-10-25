<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A resident's home: their own instance of the residence room. Closed to
 * visitors unless the owner opens it. Created by App\Domain\Housing\Residences.
 *
 * @property int $id
 * @property int $user_id
 * @property int $room_instance_id
 * @property bool $is_open
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $owner
 * @property-read RoomInstance $instance
 * @property-read Collection<int, HomeFurniture> $furniture
 */
#[Fillable(['user_id', 'room_instance_id', 'is_open'])]
class Home extends Model
{
    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<RoomInstance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(RoomInstance::class, 'room_instance_id');
    }

    /** @return HasMany<HomeFurniture, $this> */
    public function furniture(): HasMany
    {
        return $this->hasMany(HomeFurniture::class);
    }

    public function admits(User $user): bool
    {
        return $this->is_open || $this->user_id === $user->id;
    }

    /**
     * What clients are told about the home they are standing in.
     *
     * @return array{id: int, owner_id: int, owner: string, is_open: bool}
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->user_id,
            'owner' => $this->owner->username,
            'is_open' => $this->is_open,
        ];
    }

    /**
     * Every placed piece, as clients draw them.
     *
     * @return list<array{x: int, y: int, item: array<string, mixed>}>
     */
    public function furniturePayload(): array
    {
        return array_values($this->furniture()->with('inventoryItem.item')->orderBy('id')->get()
            ->map(fn (HomeFurniture $piece): array => $piece->toPayload())
            ->all());
    }
}
