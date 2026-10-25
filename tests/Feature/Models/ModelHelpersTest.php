<?php

use App\Domain\Moderation\ReportStatus;
use App\Domain\Presence\InstanceMembers;
use App\Models\ChatReport;
use App\Models\RoomInstance;
use App\Models\User;

test('the banned scope and isPermanentlyBanned read banned_until', function () {
    $permanent = User::factory()->create(['banned_until' => User::PERMANENT_BAN_UNTIL]);
    $temporary = User::factory()->create(['banned_until' => now()->addDay()]);
    User::factory()->create(['banned_until' => now()->subMinute()]);
    User::factory()->create();

    expect(User::query()->banned()->pluck('id')->sort()->values()->all())->toBe([$permanent->id, $temporary->id])
        ->and($permanent->fresh()->isPermanentlyBanned())->toBeTrue()
        ->and($temporary->fresh()->isPermanentlyBanned())->toBeFalse();
});

test('residents are named by their username in the admin panel', function () {
    $user = User::factory()->create(['username' => 'Pebble_Waddle']);

    expect($user->getFilamentName())->toBe('Pebble_Waddle');
});

test('the open scope lists unresolved reports', function () {
    $open = ChatReport::factory()->create();
    ChatReport::factory()->create(['status' => ReportStatus::Resolved]);

    expect(ChatReport::query()->open()->pluck('id')->all())->toBe([$open->id]);
});

test('member counts can be read for a set of instances', function () {
    [$empty, $busy] = RoomInstance::factory()->count(2)->create()->all();
    app(InstanceMembers::class)->add($busy->id, 7);
    app(InstanceMembers::class)->add($busy->id, 8);

    expect(app(InstanceMembers::class)->countsFor([$empty, $busy]))->toBe([$empty->id => 0, $busy->id => 2]);
});
