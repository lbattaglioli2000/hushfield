<?php

namespace Database\Factories;

use App\Domain\Chat\FilterLevel;
use App\Domain\Identity\StaffRole;
use App\Models\Avatar;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => 'resident_'.fake()->unique()->numberBetween(1000, 99999999),
            'email' => fake()->unique()->safeEmail(),
            'birth_year' => (int) now()->year - 25,
            'is_minor' => false,
            'chat_filter_level' => FilterLevel::Strict,
            'direct_messages_allowed' => true,
            'friend_requests_allowed' => true,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function minor(): static
    {
        return $this->state(fn (array $attributes) => [
            'birth_year' => (int) now()->year - 15,
            'is_minor' => true,
            'direct_messages_allowed' => false,
            'friend_requests_allowed' => false,
        ]);
    }

    public function staff(StaffRole $role = StaffRole::Moderator): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }

    public function withAvatar(): static
    {
        return $this->afterCreating(fn (User $user) => Avatar::factory()->for($user)->create());
    }
}
