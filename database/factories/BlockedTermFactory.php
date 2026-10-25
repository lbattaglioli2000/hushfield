<?php

namespace Database\Factories;

use App\Domain\Chat\BlockedTermMatchType;
use App\Domain\Chat\BlockedTermSeverity;
use App\Models\BlockedTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockedTerm>
 */
class BlockedTermFactory extends Factory
{
    public function definition(): array
    {
        return [
            'term' => fake()->unique()->word(),
            'match_type' => BlockedTermMatchType::Exact,
            'severity' => BlockedTermSeverity::High,
        ];
    }
}
