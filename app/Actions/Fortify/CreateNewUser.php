<?php

namespace App\Actions\Fortify;

use App\Actions\Identity\PassAgeGate;
use App\Actions\Identity\RegisterResident;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Fortify's registration hook. Checks the age gate before anything else and
 * creates no account for anyone under the minimum age.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private readonly PassAgeGate $passAgeGate,
        private readonly RegisterResident $registerResident,
    ) {}

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        ($this->passAgeGate)($input, request()->cookie((string) config('hushfield.accounts.underage_cookie')));

        Validator::make($input, [
            'username' => $this->usernameRules(),
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ], [
            'username.unique' => __('hushfield.username_taken'),
        ])->validate();

        $user = ($this->registerResident)($input['username'], $input['email'], $input['password'], (int) $input['birth_year']);

        request()->session()->forget('registration.birth');

        return $user;
    }
}
