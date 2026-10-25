<?php

use App\Domain\Identity\StaffRole;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->staff(StaffRole::Admin)->create();
    $this->actingAs($this->admin);
});

test('an admin promotes a player and the change is audited', function () {
    $player = resident();

    Livewire::test(ViewUser::class, ['record' => $player->getRouteKey()])
        ->callAction('staffRole', data: ['role' => StaffRole::Moderator->value, 'reason' => 'Trusted helper'])
        ->assertHasNoActionErrors()
        ->assertNotified('Staff role updated');

    $entry = Activity::query()->where('event', 'staff.role_changed')->sole();

    expect($player->fresh()->role)->toBe(StaffRole::Moderator)
        ->and($entry->log_name)->toBe('staff')
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->subject_id)->toBe($player->id)
        ->and($entry->properties->all())->toBe(['from' => null, 'to' => 'moderator', 'reason' => 'Trusted helper']);
});

test('an admin demotes staff to player by clearing the role', function () {
    $moderator = User::factory()->staff()->create();

    Livewire::test(ViewUser::class, ['record' => $moderator->getRouteKey()])
        ->assertActionDataSet(['role' => StaffRole::Moderator])
        ->callAction('staffRole', data: ['role' => null, 'reason' => 'Stepped down'])
        ->assertHasNoActionErrors();

    expect($moderator->fresh()->role)->toBeNull()
        ->and(Activity::query()->where('event', 'staff.role_changed')->sole()->properties['to'])->toBeNull();
});

test('saving the same role writes no audit entry', function () {
    $moderator = User::factory()->staff()->create();

    Livewire::test(ViewUser::class, ['record' => $moderator->getRouteKey()])
        ->callAction('staffRole', data: ['role' => StaffRole::Moderator->value, 'reason' => 'No change'])
        ->assertHasNoActionErrors();

    expect(Activity::query()->where('event', 'staff.role_changed')->exists())->toBeFalse();
});

test('a reason is required', function () {
    $player = resident();

    Livewire::test(ViewUser::class, ['record' => $player->getRouteKey()])
        ->callAction('staffRole', data: ['role' => StaffRole::Admin->value, 'reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($player->fresh()->role)->toBeNull();
});

test('an admin cannot change their own role', function () {
    Livewire::test(ViewUser::class, ['record' => $this->admin->getRouteKey()])
        ->assertActionHidden('staffRole');

    expect($this->admin->can('manageRole', $this->admin))->toBeFalse();
});

test('moderators cannot change roles', function () {
    $this->actingAs(User::factory()->staff()->create());

    Livewire::test(ViewUser::class, ['record' => resident()->getRouteKey()])
        ->assertActionHidden('staffRole');
});

test('the console promotes a resident found by email, audited as System', function () {
    $player = resident(['email' => 'moth@example.com']);

    $this->artisan('hushfield:grant-role', ['user' => 'MOTH@example.com', 'role' => 'moderator', '--reason' => 'First moderator'])
        ->expectsOutput("{$player->username} is now a moderator.")
        ->assertSuccessful();

    $entry = Activity::query()->where('event', 'staff.role_changed')->sole();

    // An admin is signed in (beforeEach), but the console must not borrow them as the causer.
    expect($player->fresh()->role)->toBe(StaffRole::Moderator)
        ->and($entry->causer_id)->toBeNull()
        ->and($entry->causer_type)->toBeNull()
        ->and($entry->subject_id)->toBe($player->id)
        ->and($entry->properties->all())->toBe(['from' => null, 'to' => 'moderator', 'reason' => 'First moderator']);
});

test('the console removes staff access by username', function () {
    $admin = User::factory()->staff(StaffRole::Admin)->create();

    $this->artisan('hushfield:grant-role', ['user' => $admin->username, 'role' => 'none', '--reason' => 'Left the team'])
        ->assertSuccessful();

    expect($admin->fresh()->role)->toBeNull()
        ->and(Activity::query()->where('event', 'staff.role_changed')->sole()->properties->all())
        ->toBe(['from' => 'admin', 'to' => null, 'reason' => 'Left the team']);
});

test('the console refuses to promote an unverified account unless told to verify it', function () {
    $player = User::factory()->unverified()->create();
    $grant = ['user' => $player->username, 'role' => 'admin', '--reason' => 'Founder'];

    $this->artisan('hushfield:grant-role', $grant)->assertFailed();

    expect($player->fresh()->role)->toBeNull()
        ->and(Activity::query()->exists())->toBeFalse();

    $this->artisan('hushfield:grant-role', [...$grant, '--verify-email' => true])->assertSuccessful();

    $player->refresh();

    expect($player->role)->toBe(StaffRole::Admin)
        ->and($player->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['staff.email_verified', 'staff.role_changed'])
        ->and(Activity::query()->whereNotNull('causer_id')->exists())->toBeFalse();
});

test('the console fails without changing anything on bad input', function (array $input) {
    $player = resident(['username' => 'lantern']);

    $this->artisan('hushfield:grant-role', $input)->assertFailed();

    expect($player->fresh()->role)->toBeNull()
        ->and(Activity::query()->exists())->toBeFalse();
})->with([
    'unknown account' => [['user' => 'nobody', 'role' => 'admin', '--reason' => 'x']],
    'unknown role' => [['user' => 'lantern', 'role' => 'owner', '--reason' => 'x']],
    'no reason' => [['user' => 'lantern', 'role' => 'admin']],
]);

test('granting the role someone already has writes no audit entry', function () {
    $moderator = User::factory()->staff()->create();

    $this->artisan('hushfield:grant-role', ['user' => $moderator->username, 'role' => 'moderator', '--reason' => 'Again'])
        ->expectsOutputToContain('Nothing changed')
        ->assertSuccessful();

    expect(Activity::query()->exists())->toBeFalse();
});
