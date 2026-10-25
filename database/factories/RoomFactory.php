<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'slug' => $slug,
            'name' => str($slug)->title()->toString(),
            'map_version' => null,
            'capacity_per_instance' => 40,
            'spawn_x' => 0,
            'spawn_y' => 0,
            'is_active' => true,
        ];
    }
}
