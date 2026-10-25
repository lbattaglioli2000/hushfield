<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $map_path
 * @property string|null $map_version
 * @property int $capacity_per_instance
 * @property int $spawn_x
 * @property int $spawn_y
 * @property bool $is_active
 */
#[Fillable(['slug', 'name', 'map_path', 'map_version', 'capacity_per_instance', 'spawn_x', 'spawn_y', 'is_active'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
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

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
