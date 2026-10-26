<?php

namespace App\Models;

use App\Domain\World\RoomKind;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property RoomKind $kind
 * @property float|null $frequency
 * @property float|null $home_frequency
 * @property int|null $town_x
 * @property int|null $town_y
 * @property string|null $map_version
 * @property int $capacity_per_instance
 * @property int $spawn_x
 * @property int $spawn_y
 * @property bool $is_active
 */
#[Fillable(['slug', 'name', 'kind', 'frequency', 'home_frequency', 'town_x', 'town_y', 'map_version', 'capacity_per_instance', 'spawn_x', 'spawn_y', 'is_active'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => RoomKind::class,
            'frequency' => 'float',
            'home_frequency' => 'float',
            'town_x' => 'integer',
            'town_y' => 'integer',
            'capacity_per_instance' => 'integer',
            'spawn_x' => 'integer',
            'spawn_y' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<RoomInstance, $this> */
    public function instances(): HasMany
    {
        return $this->hasMany(RoomInstance::class);
    }

    /**
     * Whether the room is part of the residences: the house or the yard. Each
     * of its instances belongs to one home and follows that home's visitor rule.
     */
    public function isResidence(): bool
    {
        return $this->kind === RoomKind::Residence || $this->kind === RoomKind::Yard;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
