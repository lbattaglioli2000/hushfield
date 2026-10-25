<?php

use App\Domain\Chat\BlockedTermMatchType;
use App\Domain\Moderation\SanctionType;
use App\Events\Chat\ChatMessageSent;
use App\Features\ChatEnabled;
use App\Features\WorldFlags;
use App\Models\BlockedTerm;
use App\Models\ChatMessage;
use App\Models\Sanction;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    fakeWorldEvents();
    $this->room = importRoom();
    $this->user = resident();
    ['token' => $this->token, 'instance' => $this->instance] = enterWorld($this->user, $this->room);
});

function postChat(string $body, ?string $socket = null)
{
    return asResident(test()->user, test()->token, $socket)
        ->postJson(route('world.instances.chat', test()->instance), ['body' => $body]);
}

test('chat is filtered, stored, and broadcast to others in the instance', function () {
    BlockedTerm::factory()->create(['term' => 'grub', 'match_type' => BlockedTermMatchType::Exact]);

    $response = postChat("you   are\u{200B} 9rUUb so see example.com", 'socket.9');

    $message = ChatMessage::query()->sole();

    $response->assertCreated()
        ->assertJsonPath('protocol', 1)
        ->assertJsonPath('id', $message->id)
        ->assertJsonPath('user_id', $this->user->id)
        ->assertJsonPath('body', 'you are (hushed) so see (hushed)');

    expect($message->body)->toBe('you are 9rUUb so see example.com')
        ->and($message->filtered_body)->toBe('you are (hushed) so see (hushed)')
        ->and($message->was_filtered)->toBeTrue()
        ->and($message->room_instance_id)->toBe($this->instance->id);

    Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $event): bool => $event->broadcastOn()->name === "presence-room.{$this->instance->id}"
        && $event->socket === 'socket.9'
        && $event->broadcastWith()['body'] === 'you are (hushed) so see (hushed)'
        && $event->broadcastWith()['id'] === $message->id);
});

test('the raw body never reaches the broadcast', function () {
    BlockedTerm::factory()->create(['term' => 'grub']);

    postChat('grub');

    Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $event): bool => ! str_contains(json_encode($event->broadcastWith()), 'grub'));
});

test('a muted resident sees their own line but nobody else does', function () {
    Sanction::factory()->for($this->user)->create(['type' => SanctionType::Mute, 'ends_at' => now()->addHour()]);

    postChat('can anyone hear me')
        ->assertCreated()
        ->assertJsonPath('id', null)
        ->assertJsonPath('body', 'can anyone hear me');

    expect(ChatMessage::query()->count())->toBe(0);
    Event::assertNotDispatched(ChatMessageSent::class);
});

test('an expired or lifted mute no longer silences', function () {
    Sanction::factory()->for($this->user)->create(['type' => SanctionType::Mute, 'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour()]);
    Sanction::factory()->for($this->user)->create(['type' => SanctionType::Mute, 'lifted_at' => now()]);

    postChat('hello again')->assertCreated();

    expect(ChatMessage::query()->count())->toBe(1);
});

test('identical repeats within 30 seconds are dropped', function () {
    postChat('hello')->assertCreated();
    // Same after normalization: case, leetspeak, and stretched letters.
    postChat('HeLLLL0')->assertStatus(202)->assertExactJson(['protocol' => 1, 'dropped' => true]);

    $this->travel(29)->seconds();
    postChat('hello')->assertStatus(202);

    expect(ChatMessage::query()->count())->toBe(1);
    Event::assertDispatchedTimes(ChatMessageSent::class, 1);
});

test('the repeat window is 30 seconds long', function () {
    postChat('hello')->assertCreated();

    // The window is a Redis key with a real TTL, which time travel cannot age, so expire it by hand.
    $redis = $this->app['redis']->connection('world');
    $keys = array_map(fn (string $key): string => substr($key, strlen('hf:')), $redis->keys('chat:last:*'));

    expect($keys)->toHaveCount(1)
        ->and($redis->ttl($keys[0]))->toBeGreaterThan(25)->toBeLessThanOrEqual(30);

    $redis->del($keys[0]);

    postChat('hello')->assertCreated();
    expect(ChatMessage::query()->count())->toBe(2);
});

test('different lines are not repeats', function () {
    postChat('hello')->assertCreated();
    postChat('hello there')->assertCreated();

    expect(ChatMessage::query()->count())->toBe(2);
});

test('chat is limited to 3 lines per 5 seconds', function () {
    postChat('one')->assertCreated();
    postChat('two')->assertCreated();
    postChat('three')->assertCreated();

    postChat('four')->assertTooManyRequests()
        ->assertJsonPath('reason', 'chat_too_fast')
        ->assertJsonPath('message', __('hushfield.chat_too_fast'));

    $this->travel(4)->seconds();
    postChat('five')->assertTooManyRequests();

    $this->travel(2)->seconds();
    postChat('six')->assertCreated();

    expect(ChatMessage::query()->pluck('body')->all())->toBe(['one', 'two', 'three', 'six']);
});

test('the chat kill switch pauses chat everywhere', function () {
    WorldFlags::set(ChatEnabled::class, false);

    postChat('hello')->assertStatus(503)->assertJsonPath('reason', 'chat_paused');

    expect(ChatMessage::query()->count())->toBe(0);
    Event::assertNotDispatched(ChatMessageSent::class);

    WorldFlags::set(ChatEnabled::class, true);

    postChat('hello')->assertCreated();
});

test('empty and overlong lines are refused with a reason the client understands', function (string $body, string $reason) {
    postChat($body)
        ->assertUnprocessable()
        ->assertExactJson(['protocol' => 1, 'reason' => $reason, 'message' => __("hushfield.{$reason}")]);

    expect(ChatMessage::query()->count())->toBe(0);
})->with([
    'empty' => ['', 'chat_empty'],
    'only invisible characters' => ["\u{200B}\u{FEFF}  \n", 'chat_empty'],
    'too long' => [str_repeat('a', 121), 'chat_too_long'],
]);

test('the length limit counts the line after invisible characters are stripped', function () {
    postChat(str_repeat('a', 120)."\u{200B}\u{200B}")->assertCreated();

    expect(ChatMessage::query()->sole()->body)->toBe(str_repeat('a', 120));
});

test('only members of the instance can chat in it', function () {
    $other = $this->room->instances()->create(['number' => 2, 'is_open' => true]);

    asResident($this->user, $this->token)
        ->postJson(route('world.instances.chat', $other), ['body' => 'hi'])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'not_in_room');
});
