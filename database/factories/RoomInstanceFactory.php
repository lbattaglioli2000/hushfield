<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\RoomInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomInstance>
 */
class RoomInstanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'number' => 1,
            'is_open' => true,
        ];
    }
}
