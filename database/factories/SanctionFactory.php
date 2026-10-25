<?php

namespace Database\Factories;

use App\Domain\Moderation\SanctionType;
use App\Models\Sanction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sanction>
 */
class SanctionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => SanctionType::Mute,
            'reason' => 'Testing',
            'starts_at' => now(),
            'ends_at' => now()->addHour(),
        ];
    }
}
