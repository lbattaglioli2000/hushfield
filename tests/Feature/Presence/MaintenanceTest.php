<?php

use App\Actions\Presence\EndWorldSession;
use App\Actions\Presence\SweepGhosts;
use App\Actions\World\CloseEmptyInstances;
use App\Actions\World\JoinRoom;
use App\Domain\Presence\InstanceMembers;
use App\Domain\World\PositionStore;
use App\Events\World\PlayerLeft;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\WorldSession;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    fakeWorldEvents();
    $this->room = importRoom();
    $this->members = app(InstanceMembers::class);
});

test('ghosts not seen for 60 seconds are swept out of the world and fresh sessions stay', function () {
    $this->freezeTime();
    $ghost = resident();
    $fresh = resident();
    ['instance' => $instance] = enterWorld($ghost, $this->room);
    enterWorld($fresh, $this->room);

    $this->travel(30)->seconds();
    WorldSession::query()->where('user_id', $fresh->id)->update(['last_seen_at' => now()]);
    $this->travel(31)->seconds();

    expect(app(SweepGhosts::class)())->toBe(1)
        ->and(WorldSession::query()->pluck('user_id')->all())->toBe([$fresh->id])
        ->and($this->members->all($instance->id))->toBe([$fresh->id])
        ->and(app(PositionStore::class)->get($ghost->id))->toBeNull()
        ->and(app(PositionStore::class)->get($fresh->id))->not->toBeNull()
        ->and($ghost->fresh()->last_room_instance_id)->toBeNull();

    Event::assertDispatched(PlayerLeft::class, fn (PlayerLeft $event): bool => $event->userId === $ghost->id
        && $event->broadcastOn()->name === "presence-room.{$instance->id}");
    Event::assertDispatchedTimes(PlayerLeft::class, 1);
});

test('a session seen exactly 60 seconds ago is not yet a ghost', function () {
    $this->freezeTime();
    enterWorld(resident(), $this->room);

    $this->travel(60)->seconds();

    expect(app(SweepGhosts::class)())->toBe(0)
        ->and(WorldSession::query()->count())->toBe(1);
});

test('empty instances above 1 close after 10 minutes', function () {
    $this->freezeTime();
    $one = $this->room->instances()->where('number', 1)->firstOrFail();
    $stale = $this->room->instances()->create(['number' => 2, 'is_open' => true, 'empty_since' => now()->subMinutes(10)]);
    $recent = $this->room->instances()->create(['number' => 3, 'is_open' => true, 'empty_since' => now()->subMinutes(9)]);
    $occupied = $this->room->instances()->create(['number' => 4, 'is_open' => true, 'empty_since' => now()->subHour()]);
    $never = $this->room->instances()->create(['number' => 5, 'is_open' => true]);
    $one->update(['empty_since' => now()->subHour()]);
    app(JoinRoom::class)(resident(), $this->room, $occupied);
    // Joining clears empty_since; put it back to simulate a stale marker on an occupied instance.
    $occupied->update(['empty_since' => now()->subHour()]);

    expect(app(CloseEmptyInstances::class)())->toBe(1)
        ->and($stale->fresh()->is_open)->toBeFalse()
        ->and($recent->fresh()->is_open)->toBeTrue()
        ->and($occupied->fresh()->is_open)->toBeTrue()
        ->and($never->fresh()->is_open)->toBeTrue()
        ->and($one->fresh()->is_open)->toBeTrue();
});

test('the last resident leaving marks the instance empty', function () {
    $this->freezeSecond();
    $user = resident();
    ['instance' => $instance] = enterWorld($user, $this->room);

    expect($instance->fresh()->empty_since)->toBeNull();

    app(EndWorldSession::class)($user);

    expect($instance->fresh()->empty_since->equalTo(now()))->toBeTrue();
});

test('chat older than 30 days is pruned unless it was reported', function () {
    $this->freezeTime();
    $instance = $this->room->instances()->firstOrFail();
    $old = ChatMessage::factory()->create(['room_instance_id' => $instance->id, 'created_at' => now()->subDays(31)]);
    $oldReported = ChatMessage::factory()->create(['room_instance_id' => $instance->id, 'created_at' => now()->subDays(31)]);
    $recent = ChatMessage::factory()->create(['room_instance_id' => $instance->id, 'created_at' => now()->subDays(29)]);
    ChatReport::factory()->create([
        'reported_user_id' => $oldReported->user_id,
        'chat_message_id' => $oldReported->id,
        'room_instance_id' => $instance->id,
    ]);
    $before = ChatMessage::query()->pluck('id')->diff([$old->id])->sort()->values()->all();

    $this->artisan('model:prune', ['--model' => [ChatMessage::class]])->assertSuccessful();

    expect(ChatMessage::query()->find($old->id))->toBeNull()
        ->and(ChatMessage::query()->find($oldReported->id))->not->toBeNull()
        ->and(ChatMessage::query()->find($recent->id))->not->toBeNull()
        ->and(ChatMessage::query()->pluck('id')->sort()->values()->all())->toBe($before);
});
