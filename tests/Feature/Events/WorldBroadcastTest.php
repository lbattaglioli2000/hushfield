<?php

use App\Actions\Presence\EndWorldSession;
use App\Actions\World\CloseInstance;
use App\Domain\Presence\InstanceMembers;
use App\Models\User;
use App\Models\WorldSession;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Broadcast;

/*
 * Reverb being down must never undo or interrupt a world action: the durable
 * state is written first, and broadcast failures are reported, not thrown.
 */

beforeEach(function () {
    $this->room = importRoom();

    Broadcast::extend('down', fn () => new class extends NullBroadcaster
    {
        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new BroadcastException('Reverb is down.');
        }
    });
    config(['broadcasting.connections.down' => ['driver' => 'down'], 'broadcasting.default' => 'down']);
    Broadcast::purge('down');
});

test('a new session still starts when the old one cannot be told it was superseded', function () {
    $user = resident();
    $old = startSession($user);

    $this->actingAs($user)->postJson(route('world.session.start'))->assertCreated();

    expect(WorldSession::query()->where('user_id', $user->id)->sole()->session_token)->not->toBe($old);
});

test('closing an instance still moves everyone out when nobody can be told', function () {
    $two = $this->room->instances()->create(['number' => 2, 'is_open' => true]);
    enterWorld(resident(), $this->room, $two);
    enterWorld(resident(), $this->room, $two);

    expect(app(CloseInstance::class)($two, User::factory()->staff()->create()))->toBe(2)
        ->and(app(InstanceMembers::class)->count($two->id))->toBe(0)
        ->and($two->fresh()->is_open)->toBeFalse();
});

test('leaving the world still completes when the room cannot be told', function () {
    $user = resident();
    ['instance' => $instance] = enterWorld($user, $this->room);

    app(EndWorldSession::class)($user);

    expect(app(InstanceMembers::class)->contains($instance->id, $user->id))->toBeFalse()
        ->and(WorldSession::query()->count())->toBe(0);
});
