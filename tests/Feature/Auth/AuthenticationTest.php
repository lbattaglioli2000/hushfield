<?php

use App\Domain\Chat\FilterLevel;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $this->get(route('login'))->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('home'));

    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertTooManyRequests();
});

test('logging in recomputes is_minor from the birth year', function () {
    $this->travelTo(now()->setDate(2026, 10, 25));

    $nowAdult = User::factory()->create(['birth_year' => 2007, 'is_minor' => true]);
    $actuallyMinor = User::factory()->create([
        'birth_year' => 2010,
        'is_minor' => false,
        'chat_filter_level' => FilterLevel::Standard,
    ]);

    $this->post(route('login.store'), ['email' => $nowAdult->email, 'password' => 'password']);
    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => $actuallyMinor->email, 'password' => 'password']);

    expect($nowAdult->fresh()->is_minor)->toBeFalse()
        ->and($actuallyMinor->fresh()->is_minor)->toBeTrue()
        ->and($actuallyMinor->fresh()->chat_filter_level)->toBe(FilterLevel::Strict);
});

test('logging in leaves an adult chosen filter level alone', function () {
    $user = User::factory()->create(['birth_year' => 1990, 'chat_filter_level' => FilterLevel::Standard]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect($user->fresh()->chat_filter_level)->toBe(FilterLevel::Standard);
});
