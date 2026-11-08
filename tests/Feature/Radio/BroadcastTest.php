<?php

use App\Actions\Radio\StartBroadcast;
use App\Actions\Radio\StopBroadcast;
use App\Domain\Identity\StaffRole;
use App\Domain\Radio\BroadcastRefused;
use App\Events\World\BroadcastChanged;
use App\Filament\Pages\Broadcasts;
use App\Models\Broadcast;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** A 3.03-second MP3 (the encoder pads it), so it airs for 4 seconds. */
const TONE = __DIR__.'/../../fixtures/audio/tone.mp3';

beforeEach(function () {
    fakeWorldEvents();
    Storage::fake('public');
    $this->travelTo(CarbonImmutable::parse('2026-10-25 20:00:00', 'UTC'));
    $this->admin = User::factory()->staff(StaffRole::Admin)->create();
});

test('a broadcast goes to every open instance of every room, and every snapshot carries it', function () {
    $square = resident();
    ['token' => $squareToken, 'instance' => $squareInstance] = enterWorld($square, importRoom('test-square'));
    $street = resident();
    ['token' => $streetToken, 'instance' => $streetInstance] = enterWorld($street, importRoom('test-street'));

    $broadcast = app(StartBroadcast::class)('The Midnight Hour', TONE, $this->admin);

    $payload = [
        'id' => $broadcast->id,
        'title' => 'The Midnight Hour',
        'url' => Storage::disk('public')->url($broadcast->path),
        'starts_at_ms' => now()->getTimestampMs(),
        'ends_at_ms' => now()->addSeconds(4)->getTimestampMs(),
    ];
    Storage::disk('public')->assertExists($broadcast->path);
    expect($broadcast->path)->toMatch('#^broadcasts/[0-9a-f]{16}\.mp3$#')
        ->and($broadcast->duration_seconds)->toBe(4);
    Event::assertDispatchedTimes(BroadcastChanged::class, 2);
    foreach ([$squareInstance->id, $streetInstance->id] as $instanceId) {
        Event::assertDispatched(BroadcastChanged::class, fn (BroadcastChanged $event): bool => $event->instanceId === $instanceId && $event->broadcast === $payload);
    }
    asResident($square, $squareToken)->getJson(route('world.instances.snapshot', $squareInstance))->assertJsonPath('broadcast', $payload);
    asResident($street, $streetToken)->getJson(route('world.instances.snapshot', $streetInstance))->assertJsonPath('broadcast', $payload);
    expect(Activity::query()->where('event', 'broadcast.started')->sole()->description)->toBe('On the air: The Midnight Hour');

    // Nothing is announced when it runs out: clients know when it ends, and later snapshots carry nothing.
    $this->travel(4)->seconds();
    asResident($square, $squareToken)->getJson(route('world.instances.snapshot', $squareInstance))->assertJsonPath('broadcast', null);
});

test('going on air takes whatever is on off the air', function () {
    $start = app(StartBroadcast::class);
    $first = $start('First', TONE, $this->admin);
    $this->travel(2)->seconds();

    $second = $start('Second', TONE, $this->admin);

    expect($first->fresh()->ends_at->equalTo(now()))->toBeTrue()
        ->and(Broadcast::query()->onAir(now())->sole()->is($second))->toBeTrue();
});

test('taking a broadcast off the air stops it for everyone at once, and only once', function () {
    $resident = resident();
    ['token' => $token, 'instance' => $instance] = enterWorld($resident, importRoom('test-square'));
    $broadcast = app(StartBroadcast::class)('The Midnight Hour', TONE, $this->admin);
    $this->travel(1)->seconds();

    expect(app(StopBroadcast::class)($broadcast, $this->admin))->toBeTrue()
        ->and(app(StopBroadcast::class)($broadcast->fresh(), $this->admin))->toBeFalse();

    Event::assertDispatchedTimes(BroadcastChanged::class, 2);
    Event::assertDispatched(BroadcastChanged::class, fn (BroadcastChanged $event): bool => $event->instanceId === $instance->id && $event->broadcast === null);
    asResident($resident, $token)->getJson(route('world.instances.snapshot', $instance))->assertJsonPath('broadcast', null);
    expect(Activity::query()->where('event', 'broadcast.stopped')->sole()->description)->toBe('Taken off the air: The Midnight Hour');
});

test('only MP3s go on the air, and none longer than the limit', function (string $contents, array $config, string $why) {
    config($config);
    $file = tempnam(sys_get_temp_dir(), 'broadcast');
    file_put_contents($file, $contents);

    expect(fn () => app(StartBroadcast::class)('Refused', $file, $this->admin))->toThrow(BroadcastRefused::class, $why);
    expect(Broadcast::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
    Event::assertNotDispatched(BroadcastChanged::class);
})->with([
    'not audio' => [str_repeat('not an mp3 ', 100), [], 'not an MP3'],
    'too long' => [(string) file_get_contents(TONE), ['hushfield.radio.max_minutes' => 0], 'runs 1 minutes; a broadcast may be up to 0'],
    'too big' => [(string) file_get_contents(TONE), ['hushfield.radio.upload_max_bytes' => 1024], 'a broadcast may be up to 0 MB'],
]);

test('an admin goes on air from the booth and takes it off again; a refused file leaves the air alone', function () {
    $this->actingAs($this->admin);

    Livewire::test(Broadcasts::class)
        ->assertSee('Off air')
        ->callAction('goOnAir', data: ['title' => '  The Midnight Hour ', 'file' => UploadedFile::fake()->createWithContent('show.mp3', (string) file_get_contents(TONE))])
        ->assertHasNoActionErrors()
        ->assertNotified('On the air: The Midnight Hour')
        ->assertSee('The Midnight Hour')
        ->callAction('stop', arguments: ['broadcast' => Broadcast::query()->sole()->id])
        ->assertNotified('Taken off the air.')
        ->assertSee('Off air')
        ->callAction('goOnAir', data: ['title' => 'Not a show', 'file' => UploadedFile::fake()->createWithContent('notes.mp3', 'not an mp3')])
        ->assertNotified("WHSH can't air that");

    expect(Broadcast::query()->sole())
        ->title->toBe('The Midnight Hour')
        ->scheduled_by->toBe($this->admin->id)
        ->ends_at->toEqual(now());
});

test('only admins reach the booth', function () {
    $this->actingAs(User::factory()->staff(StaffRole::Moderator)->create());

    $this->get(Broadcasts::getUrl())->assertForbidden();
});
