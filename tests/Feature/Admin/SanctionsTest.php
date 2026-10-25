<?php

use App\Domain\Moderation\SanctionType;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\SanctionsRelationManager;
use App\Models\Sanction;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    fakeWorldEvents();
    $this->moderator = User::factory()->staff()->create();
    $this->actingAs($this->moderator);
});

test('a moderator mutes a player from their page', function () {
    $player = resident();

    Livewire::test(ViewUser::class, ['record' => $player->getRouteKey()])
        ->callAction('mute', data: ['reason' => 'Spam', 'ends_at' => now()->addDay()->toDateTimeString()])
        ->assertHasNoActionErrors()
        ->assertNotified('Mute issued');

    expect($player->isMuted())->toBeTrue()
        ->and(Activity::query()->where('event', 'sanction.mute')->sole()->causer_id)->toBe($this->moderator->id);
});

test('staff cannot sanction other staff', function () {
    $colleague = User::factory()->staff()->create();

    Livewire::test(ViewUser::class, ['record' => $colleague->getRouteKey()])
        ->assertActionHidden('warning')
        ->assertActionHidden('mute')
        ->assertActionHidden('ban');

    expect($this->moderator->can('sanction', $colleague))->toBeFalse();
});

test('a moderator lifts a ban from the sanctions history', function () {
    $player = resident(['banned_until' => now()->addDay()]);
    $ban = Sanction::factory()->for($player)->create(['type' => SanctionType::Ban, 'ends_at' => now()->addDay()]);

    Livewire::test(SanctionsRelationManager::class, ['ownerRecord' => $player, 'pageClass' => ViewUser::class])
        ->assertCanSeeTableRecords([$ban])
        ->callAction(TestAction::make('lift')->table($ban), data: ['reason' => 'Appeal accepted'])
        ->assertHasNoActionErrors()
        ->assertNotified('Sanction lifted');

    expect($ban->fresh()->lifted_by)->toBe($this->moderator->id)
        ->and($player->fresh()->isBanned())->toBeFalse()
        ->and(Activity::query()->where('event', 'sanction.lifted')->sole()->properties['reason'])->toBe('Appeal accepted');
});

test('lifted sanctions cannot be lifted again', function () {
    $player = resident();
    $lifted = Sanction::factory()->for($player)->create(['lifted_at' => now(), 'lifted_by' => $this->moderator->id]);

    Livewire::test(SanctionsRelationManager::class, ['ownerRecord' => $player, 'pageClass' => ViewUser::class])
        ->assertActionHidden(TestAction::make('lift')->table($lifted));
});
