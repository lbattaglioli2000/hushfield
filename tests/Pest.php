<?php

use App\Actions\Presence\StartWorldSession;
use App\Actions\World\ImportMap;
use App\Actions\World\JoinRoom;
use App\Domain\Chat\BlockedTermMatchType;
use App\Domain\Chat\BlockedTermSeverity;
use App\Events\Chat\ChatMessageSent;
use App\Events\Moderation\PlayerWarned;
use App\Events\Moderation\WorldAccessRevoked;
use App\Events\Presence\InstanceReassigned;
use App\Events\Presence\SessionSuperseded;
use App\Events\World\PlayerJoined;
use App\Events\World\PlayerLeft;
use App\Events\World\PlayerMoved;
use App\Http\Middleware\EnsureWorldSession;
use App\Models\BlockedTerm;
use App\Models\Room;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every test boots the application (tests/TestCase.php points the map path at
| tests/fixtures/maps and flushes the test-only world Redis database).
| Unit tests never touch the database. Feature tests run inside a database
| transaction; the Octane suite commits its data because a second application
| instance has to see it.
|
*/

pest()->extend(TestCase::class)->in('Unit', 'Feature', 'Octane');

pest()->use(RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** Every broadcast event the world sends. Faking only these keeps model events and auth listeners real. */
const WORLD_EVENTS = [
    PlayerJoined::class,
    PlayerLeft::class,
    PlayerMoved::class,
    ChatMessageSent::class,
    SessionSuperseded::class,
    WorldAccessRevoked::class,
    InstanceReassigned::class,
    PlayerWarned::class,
];

function fakeWorldEvents(): void
{
    Event::fake(WORLD_EVENTS);
}

/**
 * Imports a fixture map from tests/fixtures/maps and returns its room.
 */
function importRoom(string $slug = 'test-square'): Room
{
    app(ImportMap::class)($slug);

    return Room::query()->where('slug', $slug)->firstOrFail();
}

function resident(array $attributes = []): User
{
    return User::factory()->withAvatar()->create($attributes)->load('avatar');
}

/**
 * Starts a world session the way the client does, and returns its token.
 */
function startSession(User $user): string
{
    return app(StartWorldSession::class)($user)->session_token;
}

/**
 * Starts a world session and joins the room (the given instance when it has room),
 * the way the client does before playing.
 *
 * @return array{token: string, instance: RoomInstance}
 */
function enterWorld(User $user, Room $room, ?RoomInstance $instance = null): array
{
    $token = startSession($user);
    $result = app(JoinRoom::class)($user, $room, $instance);

    return ['token' => $token, 'instance' => $result->instance];
}

/**
 * The current test, acting as the resident and sending their world session token.
 */
function asResident(User $user, string $token, ?string $socketId = null): TestCase
{
    return test()->actingAs($user)->withHeaders(worldHeaders($token, $socketId));
}

/**
 * @return array<string, string>
 */
function worldHeaders(string $token, ?string $socketId = null): array
{
    return array_filter([
        EnsureWorldSession::HEADER => $token,
        'X-Socket-ID' => $socketId,
    ]);
}

function blockTerm(string $term, BlockedTermMatchType $type = BlockedTermMatchType::Exact, BlockedTermSeverity $severity = BlockedTermSeverity::High): void
{
    BlockedTerm::factory()->create(['term' => $term, 'match_type' => $type, 'severity' => $severity]);
}
