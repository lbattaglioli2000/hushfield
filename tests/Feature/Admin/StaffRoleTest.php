<?php

use App\Domain\Identity\StaffRole;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
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
