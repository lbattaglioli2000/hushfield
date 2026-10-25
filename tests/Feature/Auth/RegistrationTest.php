<?php

use App\Domain\Chat\BlockedTermMatchType;
use App\Domain\Chat\FilterLevel;
use App\Models\BlockedTerm;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());

    // A fixed "today" in Hushfield time so the age gate cases are exact.
    $this->travelTo(now('America/New_York')->setDate(2026, 10, 25)->setTime(12, 0));
});

function registration(array $overrides = []): array
{
    return [
        'username' => 'Pebble_Waddle',
        'email' => 'pebble@example.com',
        'birth_month' => 3,
        'birth_year' => 1995,
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$overrides,
    ];
}

test('registration screen can be rendered', function () {
    $this->get(route('register'))->assertOk();
});

test('new residents can register', function () {
    $response = $this->post(route('register.store'), registration());

    $response->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('username', 'Pebble_Waddle')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    expect($user->email)->toBe('pebble@example.com')
        ->and($user->birth_year)->toBe(1995)
        ->and($user->is_minor)->toBeFalse()
        ->and($user->chat_filter_level)->toBe(FilterLevel::Strict)
        ->and($user->direct_messages_allowed)->toBeTrue()
        ->and($user->avatar)->not->toBeNull();
});

test('teenagers register as minors with social features off', function () {
    // Turned 13 in September 2026.
    $this->post(route('register.store'), registration(['birth_month' => 9, 'birth_year' => 2013]))
        ->assertSessionHasNoErrors();

    $user = User::query()->where('username', 'Pebble_Waddle')->firstOrFail();

    expect($user->is_minor)->toBeTrue()
        ->and($user->chat_filter_level)->toBe(FilterLevel::Strict)
        ->and($user->direct_messages_allowed)->toBeFalse()
        ->and($user->friend_requests_allowed)->toBeFalse();
});

test('registration under 13 is refused with no account and the age gate cookie set', function () {
    // Turns 13 in November 2026: not yet.
    $response = $this->post(route('register.store'), registration(['birth_month' => 11, 'birth_year' => 2013]));

    $response->assertSessionHasErrors('underage')
        ->assertCookie('hf_age_gate', '1');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('the age gate cookie blocks a retry with an older birth date', function () {
    $this->withCookie('hf_age_gate', '1')
        ->post(route('register.store'), registration())
        ->assertSessionHasErrors('underage');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('the age gate is checked before anything else', function () {
    // An invalid username must not reveal anything before the age check fails.
    $this->post(route('register.store'), registration(['birth_month' => 1, 'birth_year' => 2020, 'username' => 'x']))
        ->assertSessionHasErrors('underage')
        ->assertSessionDoesntHaveErrors('username');
});

test('birth month and year are required and must be real', function (array $input, string $error) {
    $this->post(route('register.store'), registration($input))->assertSessionHasErrors($error);

    expect(User::query()->count())->toBe(0);
})->with([
    'missing month' => [['birth_month' => null], 'birth_month'],
    'month 13' => [['birth_month' => 13], 'birth_month'],
    'missing year' => [['birth_year' => null], 'birth_year'],
    'future year' => [['birth_year' => 2030], 'birth_year'],
    'too long ago' => [['birth_year' => 1890], 'birth_year'],
]);

test('reserved usernames are refused', function (string $username) {
    $this->post(route('register.store'), registration(['username' => $username]))
        ->assertSessionHasErrors(['username' => __('hushfield.username_reserved')]);

    expect(User::query()->count())->toBe(0);
})->with(['admin', 'ADMIN', 'Mod_Pebble', 'pebble_staff', 'SuperAdmin99', 'm0d3rat0r', 'Hushfield_Fan']);

test('usernames containing a blocked term are refused', function (string $username) {
    BlockedTerm::factory()->create(['term' => 'grub', 'match_type' => BlockedTermMatchType::Exact]);

    $this->post(route('register.store'), registration(['username' => $username]))
        ->assertSessionHasErrors(['username' => __('hushfield.username_blocked')]);
})->with(['grub', 'Big_Grub', 'xx9rubxx']);

test('usernames must be 3 to 16 letters, numbers, and single underscores', function (string $username) {
    $this->post(route('register.store'), registration(['username' => $username]))
        ->assertSessionHasErrors(['username' => __('hushfield.username_format')]);
})->with(['ab', str_repeat('a', 17), 'pebble__waddle', '_pebble', 'pebble_', 'peb ble', 'pebble!', 'pébble']);

test('usernames are unique regardless of case', function () {
    User::factory()->create(['username' => 'Pebble_Waddle']);

    $this->post(route('register.store'), registration(['username' => 'pEBBLE_wADDLE']))
        ->assertSessionHasErrors(['username' => __('hushfield.username_taken')]);

    expect(User::query()->count())->toBe(1);
});

test('emails are unique regardless of case', function () {
    User::factory()->create(['email' => 'pebble@example.com']);

    $this->post(route('register.store'), registration(['email' => 'PEBBLE@example.com']))
        ->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(1);
});
