<?php

namespace App\Actions\Identity;

use App\Models\Avatar;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class RegisterResident
{
    public function __construct(private ApplyAccountPolicy $applyAccountPolicy) {}

    public function __invoke(string $username, string $email, string $password, int $birthYear): User
    {
        return DB::transaction(function () use ($username, $email, $password, $birthYear): User {
            $user = new User([
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'birth_year' => $birthYear,
            ]);

            ($this->applyAccountPolicy)($user);

            $user->avatar()->create([
                'body_color' => Avatar::BODY_COLORS[array_rand(Avatar::BODY_COLORS)],
                'sprite_key' => 'resident',
            ]);

            return $user;
        });
    }
}
