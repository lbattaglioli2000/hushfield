<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    public function definition(): array
    {
        $body = fake()->sentence(4);

        return [
            'room_instance_id' => RoomInstance::factory(),
            'user_id' => User::factory(),
            'body' => $body,
            'filtered_body' => $body,
            'was_filtered' => false,
        ];
    }
}
