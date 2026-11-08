<?php

use App\Actions\Radio\CancelBroadcast;
use App\Actions\Radio\ScheduleBroadcast;
use App\Domain\Identity\StaffRole;
use App\Domain\Radio\Airwaves;
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

    $broadcast = app(ScheduleBroadcast::class)('The Midnight Hour', TONE, $this->admin);

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
        Event::assertDispatched(BroadcastChanged::class, fn (BroadcastChanged $event): bool => $event->instanceId === $instanceId && $event->broadcasts === [$payload]);
    }
    asResident($square, $squareToken)->getJson(route('world.instances.snapshot', $squareInstance))->assertJsonPath('broadcasts', [$payload]);
    asResident($street, $streetToken)->getJson(route('world.instances.snapshot', $streetInstance))->assertJsonPath('broadcasts', [$payload]);
    expect(Activity::query()->where('event', 'broadcast.started')->sole()->description)->toBe('On the air: The Midnight Hour');

    // Nothing is announced when it runs out: clients know when it ends, and later snapshots carry nothing.
    $this->travel(4)->seconds();
    asResident($square, $squareToken)->getJson(route('world.instances.snapshot', $squareInstance))->assertJsonPath('broadcasts', []);
});

test('going on air takes whatever is on off the air', function () {
    $start = app(ScheduleBroadcast::class);
    $first = $start('First', TONE, $this->admin);
    $this->travel(2)->seconds();

    $second = $start('Second', TONE, $this->admin);

    expect($first->fresh()->ends_at->equalTo(now()))->toBeTrue()
        ->and(app(Airwaves::class)->onAir(now())?->is($second))->toBeTrue();
});

test('taking a broadcast off the air stops it for everyone at once, and only once', function () {
    $resident = resident();
    ['token' => $token, 'instance' => $instance] = enterWorld($resident, importRoom('test-square'));
    $broadcast = app(ScheduleBroadcast::class)('The Midnight Hour', TONE, $this->admin);
    $this->travel(1)->seconds();

    expect(app(CancelBroadcast::class)($broadcast, $this->admin))->toBeTrue()
        ->and(app(CancelBroadcast::class)($broadcast->fresh(), $this->admin))->toBeFalse();

    Event::assertDispatchedTimes(BroadcastChanged::class, 2);
    Event::assertDispatched(BroadcastChanged::class, fn (BroadcastChanged $event): bool => $event->instanceId === $instance->id && $event->broadcasts === []);
    asResident($resident, $token)->getJson(route('world.instances.snapshot', $instance))->assertJsonPath('broadcasts', []);
    expect(Activity::query()->where('event', 'broadcast.stopped')->sole()->description)->toBe('Taken off the air: The Midnight Hour');
});

/** The lineup as it stands, in Hushfield-free UTC times: title, start, end. */
function lineup(): array
{
    return array_map(fn (Broadcast $broadcast): array => [$broadcast->title, $broadcast->starts_at->format('H:i:s'), $broadcast->ends_at->format('H:i:s')], app(Airwaves::class)->lineup(now()));
}

test('a scheduled broadcast goes on the air by itself at its time, cutting short what is on, and nothing moves it', function () {
    $schedule = app(ScheduleBroadcast::class);
    $resident = resident();
    ['token' => $token, 'instance' => $instance] = enterWorld($resident, importRoom('test-square'));

    $live = $schedule('Live', TONE, $this->admin);
    $later = $schedule('Later', TONE, $this->admin, now()->addSeconds(2));

    // Live gives way at 20:00:02, without anyone pressing anything.
    expect(lineup())->toBe([['Live', '20:00:00', '20:00:02'], ['Later', '20:00:02', '20:00:06']])
        ->and(Activity::query()->where('event', 'broadcast.scheduled')->sole()->description)->toBe('Scheduled: Later');
    Event::assertDispatched(BroadcastChanged::class, fn (BroadcastChanged $event): bool => $event->instanceId === $instance->id
        && array_column($event->broadcasts, 'title') === ['Live', 'Later']
        && $event->broadcasts[0]['ends_at_ms'] === $event->broadcasts[1]['starts_at_ms']);

    $this->travel(3)->seconds();
    expect(app(Airwaves::class)->onAir(now())?->is($later))->toBeTrue();
    asResident($resident, $token)->getJson(route('world.instances.snapshot', $instance))->assertJsonPath('broadcasts.0.title', 'Later');

    // Going on air now takes Later off, and does not bring Live back once it is over.
    $this->travel(1)->seconds();
    $schedule('Now', TONE, $this->admin);
    expect(lineup())->toBe([['Now', '20:00:04', '20:00:08']]);
});

test('a scheduled broadcast must be in the future and clear of every other one', function () {
    $schedule = app(ScheduleBroadcast::class);
    $schedule('First', TONE, $this->admin, now()->addSeconds(10));

    expect(fn () => $schedule('Past', TONE, $this->admin, now()))->toThrow(BroadcastRefused::class, 'has to start in the future')
        ->and(fn () => $schedule('Overlaps', TONE, $this->admin, now()->addSeconds(12)))->toThrow(BroadcastRefused::class, 'It would overlap "First"')
        ->and(fn () => $schedule('Runs into it', TONE, $this->admin, now()->addSeconds(7)))->toThrow(BroadcastRefused::class, 'It would overlap "First"');

    // Back to back is fine, before and after.
    $schedule('Before', TONE, $this->admin, now()->addSeconds(6));
    $schedule('After', TONE, $this->admin, now()->addSeconds(14));

    expect(array_column(lineup(), 0))->toBe(['Before', 'First', 'After'])
        ->and(Storage::disk('public')->allFiles())->toHaveCount(1);
});

test('removing a scheduled broadcast lets what is on play out, and moves nothing else', function () {
    $schedule = app(ScheduleBroadcast::class);
    $live = $schedule('Live', TONE, $this->admin);
    $cut = $schedule('Cut in', TONE, $this->admin, now()->addSeconds(2));
    $schedule('Later', TONE, $this->admin, now()->addSeconds(30));

    expect(app(CancelBroadcast::class)($cut, $this->admin))->toBeTrue();

    expect(lineup())->toBe([['Live', '20:00:00', '20:00:04'], ['Later', '20:00:30', '20:00:34']])
        ->and(Broadcast::query()->find($cut->id))->toBeNull()
        ->and(Activity::query()->where('event', 'broadcast.removed')->sole()->description)->toBe('Removed from the schedule: Cut in');
});

test('only MP3s go on the air, and none longer than the limit', function (string $contents, array $config, string $why) {
    config($config);
    $file = tempnam(sys_get_temp_dir(), 'broadcast');
    file_put_contents($file, $contents);

    expect(fn () => app(ScheduleBroadcast::class)('Refused', $file, $this->admin))->toThrow(BroadcastRefused::class, $why);
    expect(Broadcast::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
    Event::assertNotDispatched(BroadcastChanged::class);
    unlink($file);
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
        ->callAction('cancel', arguments: ['broadcast' => Broadcast::query()->sole()->id])
        ->assertNotified('Taken off the air.')
        ->assertSee('Off air')
        ->callAction('goOnAir', data: ['title' => 'Not a show', 'file' => UploadedFile::fake()->createWithContent('notes.mp3', 'not an mp3')])
        ->assertNotified("WHSH can't air that");

    expect(Broadcast::query()->sole())
        ->title->toBe('The Midnight Hour')
        ->scheduled_by->toBe($this->admin->id)
        ->ends_at->toEqual(now());
});

test('from the booth an admin schedules a broadcast in Hushfield time, sees it coming up, and removes it', function () {
    $this->actingAs($this->admin);

    $page = Livewire::test(Broadcasts::class)
        // 20:00 UTC is 4 PM in Hushfield; the broadcast is ten minutes from now there.
        ->callAction('schedule', data: ['title' => 'Night Shift', 'starts_at' => '2026-10-25 16:10:00', 'file' => UploadedFile::fake()->createWithContent('show.mp3', (string) file_get_contents(TONE))])
        ->assertHasNoActionErrors()
        ->assertNotified('Scheduled: Night Shift, at Sun 25 Oct, 4:10 PM')
        ->assertSee('Off air')
        ->assertSee('The next broadcast starts at Sun 25 Oct, 4:10 PM.');

    $broadcast = Broadcast::query()->sole();
    expect($broadcast->starts_at->toIso8601String())->toBe('2026-10-25T20:10:00+00:00');

    $page->callAction('cancel', arguments: ['broadcast' => $broadcast->id])
        ->assertNotified('Removed from the schedule.')
        ->assertSee('Nothing is scheduled.');
    expect(Broadcast::query()->count())->toBe(0);
});

test('only admins reach the booth', function () {
    $this->actingAs(User::factory()->staff(StaffRole::Moderator)->create());

    $this->get(Broadcasts::getUrl())->assertForbidden();
});
