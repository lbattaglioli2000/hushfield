<?php

use App\Actions\Moderation\IssueSanction;
use App\Domain\Moderation\SanctionType;
use App\Features\VoiceEnabled;
use App\Features\WorldFlags;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

const VOICE_SECRET = 'test-secret-that-is-long-enough-for-hs256';

beforeEach(function () {
    fakeWorldEvents();
    Http::preventStrayRequests();
    Http::fake(['voice.test/twirp/*' => Http::response([])]);
    config([
        'hushfield.voice.url' => 'wss://voice.test',
        'hushfield.voice.api_key' => 'APItest',
        'hushfield.voice.api_secret' => VOICE_SECRET,
    ]);
    $this->room = importRoom();
});

function askForVoice(User $user, ?string $token = null, $instance = null): TestResponse
{
    $token ??= test()->token;
    $instance ??= test()->instance;

    return asResident($user, $token)->postJson(route('world.instances.voice', ['instance' => $instance]));
}

/**
 * The claims of a LiveKit token, after checking it was signed with the API secret.
 *
 * @return array<string, mixed>
 */
function voiceClaims(string $jwt): array
{
    [$header, $payload, $signature] = explode('.', $jwt);
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", VOICE_SECRET, true)), '+/', '-_'), '=');

    expect($signature)->toBe($expected);

    return json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
}

function assertEvicted(User $user, int $instanceId): void
{
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://voice.test/twirp/livekit.RoomService/RemoveParticipant'
        && $request['room'] === "instance-{$instanceId}"
        && $request['identity'] === (string) $user->id
        && voiceClaims(substr($request->header('Authorization')[0], 7))['video'] === ['room' => "instance-{$instanceId}", 'roomAdmin' => true]);
}

test('an adult in the room gets a token to speak and listen in that instance only, with a microphone only', function () {
    $adult = resident();
    ['token' => $this->token, 'instance' => $this->instance] = enterWorld($adult, $this->room);

    $response = askForVoice($adult)->assertOk()
        ->assertJsonPath('url', 'wss://voice.test')
        ->assertJsonPath('can_speak', true);
    $claims = voiceClaims($response->json('token'));

    expect($claims['iss'])->toBe('APItest')
        ->and($claims['sub'])->toBe((string) $adult->id)
        ->and($claims['name'])->toBe($adult->username)
        ->and($claims['exp'] - $claims['nbf'])->toBe(300)
        ->and($claims['video'])->toMatchArray([
            'room' => "instance-{$this->instance->id}",
            'roomJoin' => true,
            'canSubscribe' => true,
            'canPublish' => true,
            'canPublishSources' => ['microphone'],
            'canPublishData' => false,
        ]);
});

test('minors cannot join voice, to speak or to listen', function () {
    $minor = User::factory()->minor()->withAvatar()->create();
    ['token' => $this->token, 'instance' => $this->instance] = enterWorld($minor, $this->room);

    askForVoice($minor)->assertForbidden()
        ->assertJsonPath('reason', 'voice_adults_only')
        ->assertJsonMissingPath('token');
});

test('a muted adult may listen but not speak', function () {
    $adult = resident();
    ['token' => $this->token, 'instance' => $this->instance] = enterWorld($adult, $this->room);
    app(IssueSanction::class)($adult, SanctionType::Mute, 'Shouting', now()->addHour(), User::factory()->staff()->create());

    $response = askForVoice($adult)->assertOk()->assertJsonPath('can_speak', false);

    expect(voiceClaims($response->json('token'))['video'])->toMatchArray(['canSubscribe' => true, 'canPublish' => false]);
});

test('nobody gets a token for an instance they are not in', function () {
    $adult = resident();
    ['token' => $this->token] = enterWorld($adult, $this->room);
    $elsewhere = $this->room->instances()->create(['number' => 9]);

    askForVoice($adult, instance: $elsewhere)->assertStatus(409)->assertJsonPath('reason', 'not_in_room');
});

test('voice is refused while switched off or not configured', function (string $off) {
    $adult = resident();
    ['token' => $this->token, 'instance' => $this->instance] = enterWorld($adult, $this->room);
    $off === 'kill switch'
        ? WorldFlags::set(VoiceEnabled::class, false)
        : config(['hushfield.voice.api_secret' => null]);

    askForVoice($adult)->assertStatus(409)->assertJsonPath('reason', 'voice_paused');
})->with(['kill switch', 'no LiveKit']);

test('the game shell offers push-to-talk to adults only', function () {
    $this->actingAs(resident())->get(route('play'))
        ->assertInertia(fn (Assert $page) => $page->where('game.voice', ['hearingTiles' => 8, 'whisperTiles' => 3]));

    $this->actingAs(User::factory()->minor()->create())->get(route('play'))
        ->assertInertia(fn (Assert $page) => $page->where('game.voice', null));

    WorldFlags::set(VoiceEnabled::class, false);

    $this->actingAs(resident())->get(route('play'))
        ->assertInertia(fn (Assert $page) => $page->where('game.voice', null));
});

test('leaving a room drops the voice connection there', function () {
    $this->withoutDefer();
    $adult = resident();
    ['token' => $token, 'instance' => $instance] = enterWorld($adult, $this->room);

    asResident($adult, $token)->postJson(route('world.session.end'))->assertSuccessful();

    assertEvicted($adult, $instance->id);
});

test('leaving a room makes no voice call for a minor, who was never connected', function () {
    $this->withoutDefer();
    $minor = User::factory()->minor()->withAvatar()->create();
    ['token' => $token] = enterWorld($minor, $this->room);

    asResident($minor, $token)->postJson(route('world.session.end'))->assertSuccessful();

    Http::assertNothingSent();
});

test('a mute drops the open voice connection straight away', function () {
    $adult = resident();
    ['instance' => $instance] = enterWorld($adult, $this->room);

    app(IssueSanction::class)($adult, SanctionType::Mute, 'Shouting', now()->addHour(), User::factory()->staff()->create());

    assertEvicted($adult, $instance->id);
});
