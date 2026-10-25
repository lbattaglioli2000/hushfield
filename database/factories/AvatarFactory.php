<?php

namespace Database\Factories;

use App\Models\Avatar;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Avatar>
 */
class AvatarFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'body_color' => fake()->randomElement(Avatar::BODY_COLORS),
            'sprite_key' => Avatar::DEFAULT_SPRITE,
        ];
    }
}
