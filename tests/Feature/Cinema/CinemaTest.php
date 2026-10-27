<?php

use App\Actions\Cinema\CancelScreening;
use App\Actions\Cinema\ScheduleScreening;
use App\Actions\World\SetWorldFlag;
use App\Domain\Cinema\Film;
use App\Domain\Cinema\FilmRefused;
use App\Domain\Cinema\YouTube;
use App\Domain\Identity\StaffRole;
use App\Events\World\CinemaChanged;
use App\Features\WorldFlag;
use App\Filament\Pages\Cinema;
use App\Models\Screening;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    fakeWorldEvents();
    $this->travelTo(CarbonImmutable::parse('2026-10-25 20:00:00', 'UTC'));
    config(['hushfield.cinema.room' => 'test-square', 'hushfield.cinema.intermission_seconds' => 60, 'hushfield.cinema.youtube_key' => 'test-key']);
    $this->cinema = importRoom('test-square');
    $this->admin = User::factory()->staff(StaffRole::Admin)->create();
});

function film(string $id = 'aaaaaaaaaaa', int $seconds = 600): Film
{
    return new Film($id, "Film {$id}", 'The VFA', $seconds, false);
}

/** What videos.list answers for one video, with $overrides merged in. */
function youTubeVideo(array $overrides = []): array
{
    return ['items' => [array_replace_recursive([
        'id' => 'dQw4w9WgXcQ',
        'snippet' => ['title' => 'Decker: Unsealed', 'channelTitle' => 'On Cinema', 'liveBroadcastContent' => 'none'],
        'contentDetails' => ['duration' => 'PT1H2M3S', 'contentRating' => []],
        'status' => ['uploadStatus' => 'processed', 'privacyStatus' => 'public', 'embeddable' => true, 'madeForKids' => false],
    ], $overrides)]];
}

/** @return list<array{title: string, starts: string, ends: string}> */
function programme(): array
{
    return Screening::query()->orderBy('starts_at')->get()->map(fn (Screening $screening): array => [
        'title' => $screening->title,
        'starts' => $screening->starts_at->format('H:i:s'),
        'ends' => $screening->ends_at->format('H:i:s'),
    ])->all();
}

test('YouTube links of every shape give their video id', function (string $input, ?string $id) {
    expect(YouTube::videoId($input))->toBe($id);
})->with([
    ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s', 'dQw4w9WgXcQ'],
    ['youtube.com/watch?list=PL1&v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'dQw4w9WgXcQ'],
    ['https://m.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    [' dQw4w9WgXcQ ', 'dQw4w9WgXcQ'],
    ['https://vimeo.com/watch?v=dQw4w9WgXcQ', null],
    ['https://www.youtube.com/watch?v=short', null],
    ['https://www.youtube.com/@oncinema', null],
]);

test('a film is looked up with its title, length and made-for-kids flag', function () {
    Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::response(youTubeVideo(['status' => ['madeForKids' => true]]))]);

    $film = app(YouTube::class)->lookup('https://youtu.be/dQw4w9WgXcQ');

    expect($film)->toEqual(new Film('dQw4w9WgXcQ', 'Decker: Unsealed', 'On Cinema', 3723, true));
    Http::assertSent(fn ($request): bool => $request['id'] === 'dQw4w9WgXcQ' && $request['key'] === 'test-key' && $request['part'] === 'snippet,contentDetails,status');
});

test('videos the screen could not play are refused before they are queued', function (array $video, string $why) {
    Http::fake(['www.googleapis.com/*' => Http::response($video)]);

    expect(fn () => app(YouTube::class)->lookup('dQw4w9WgXcQ'))->toThrow(FilmRefused::class, $why);
})->with([
    'missing' => [['items' => []], 'no public video'],
    'not embeddable' => [youTubeVideo(['status' => ['embeddable' => false]]), 'does not allow'],
    'private' => [youTubeVideo(['status' => ['privacyStatus' => 'private']]), 'not available'],
    'live' => [youTubeVideo(['snippet' => ['liveBroadcastContent' => 'live'], 'contentDetails' => ['duration' => 'P0D']]), 'Live streams'],
    'age-restricted' => [youTubeVideo(['contentDetails' => ['contentRating' => ['ytRating' => 'ytAgeRestricted']]]), 'age-restricted'],
    'too long' => [youTubeVideo(['contentDetails' => ['duration' => 'PT4H0M1S']]), '240 minutes'],
]);

test('without an API key nothing is looked up', function () {
    config(['hushfield.cinema.youtube_key' => null]);
    Http::fake();

    expect(fn () => app(YouTube::class)->lookup('dQw4w9WgXcQ'))->toThrow(FilmRefused::class, 'YOUTUBE_DATA_API_KEY');
    Http::assertNothingSent();
});

test('queued films run back to back, an intermission apart, starting at once on an empty screen', function () {
    $schedule = app(ScheduleScreening::class);

    $schedule(film('aaaaaaaaaaa', 600), false, $this->admin);
    $schedule(film('bbbbbbbbbbb', 90), false, $this->admin);
    $schedule(film('ccccccccccc', 30), false, $this->admin);

    expect(programme())->toBe([
        ['title' => 'Film aaaaaaaaaaa', 'starts' => '20:00:00', 'ends' => '20:10:00'],
        ['title' => 'Film bbbbbbbbbbb', 'starts' => '20:11:00', 'ends' => '20:12:30'],
        ['title' => 'Film ccccccccccc', 'starts' => '20:13:30', 'ends' => '20:14:00'],
    ]);
});

test('playing a film now cuts the one showing short and moves the queue back behind it', function () {
    $schedule = app(ScheduleScreening::class);
    $schedule(film('aaaaaaaaaaa', 600), false, $this->admin);
    $schedule(film('bbbbbbbbbbb', 300), false, $this->admin);
    $this->travel(2)->minutes();

    $schedule(film('ccccccccccc', 120), true, $this->admin);

    expect(programme())->toBe([
        ['title' => 'Film aaaaaaaaaaa', 'starts' => '20:00:00', 'ends' => '20:02:00'],
        ['title' => 'Film ccccccccccc', 'starts' => '20:02:00', 'ends' => '20:04:00'],
        ['title' => 'Film bbbbbbbbbbb', 'starts' => '20:05:00', 'ends' => '20:10:00'],
    ]);
});

test('stopping the film showing moves the queue up; removing a queued one gives its slot to the next', function () {
    $schedule = app(ScheduleScreening::class);
    $cancel = app(CancelScreening::class);
    $first = $schedule(film('aaaaaaaaaaa', 600), false, $this->admin);
    $second = $schedule(film('bbbbbbbbbbb', 300), false, $this->admin);
    $schedule(film('ccccccccccc', 120), false, $this->admin);
    $this->travel(3)->minutes();

    expect($cancel($second, $this->admin))->toBeTrue();
    expect(programme())->toBe([
        ['title' => 'Film aaaaaaaaaaa', 'starts' => '20:00:00', 'ends' => '20:10:00'],
        ['title' => 'Film ccccccccccc', 'starts' => '20:11:00', 'ends' => '20:13:00'],
    ]);

    expect($cancel($first, $this->admin))->toBeTrue();
    expect(programme())->toBe([
        ['title' => 'Film aaaaaaaaaaa', 'starts' => '20:00:00', 'ends' => '20:03:00'],
        ['title' => 'Film ccccccccccc', 'starts' => '20:04:00', 'ends' => '20:06:00'],
    ]);

    // Once over, a film can't be taken off again.
    expect($cancel($first->fresh(), $this->admin))->toBeFalse();

    expect(Activity::query()->where('log_name', 'cinema')->pluck('event')->all())
        ->toBe(['screening.queued', 'screening.queued', 'screening.queued', 'screening.removed', 'screening.stopped']);
});

test('everyone in every open instance of the cinema hears the programme change', function () {
    $first = enterWorld(resident(), $this->cinema)['instance'];
    $second = $this->cinema->instances()->create(['number' => 2, 'is_open' => true]);
    $this->cinema->instances()->create(['number' => 3, 'is_open' => false]);

    $screening = app(ScheduleScreening::class)(film(), false, $this->admin);

    Event::assertDispatchedTimes(CinemaChanged::class, 2);
    foreach ([$first->id, $second->id] as $instanceId) {
        Event::assertDispatched(CinemaChanged::class, fn (CinemaChanged $event): bool => $event->instanceId === $instanceId
            && $event->cinema === ['open' => true, 'screenings' => [$screening->toPayload()]]);
    }
});

test('the cinema snapshot carries the programme, in server milliseconds, and other rooms carry none', function () {
    $screening = app(ScheduleScreening::class)(film('aaaaaaaaaaa', 600), false, $this->admin);
    $resident = resident();
    ['token' => $token, 'instance' => $instance] = enterWorld($resident, $this->cinema);

    asResident($resident, $token)->getJson(route('world.instances.snapshot', $instance))
        ->assertOk()
        ->assertJsonPath('cinema', ['open' => true, 'screenings' => [[
            'id' => $screening->id,
            'video_id' => 'aaaaaaaaaaa',
            'title' => 'Film aaaaaaaaaaa',
            'starts_at_ms' => now()->getTimestampMs(),
            'ends_at_ms' => now()->addMinutes(10)->getTimestampMs(),
        ]]]);

    $street = importRoom('test-street');
    $other = resident();
    ['token' => $otherToken, 'instance' => $streetInstance] = enterWorld($other, $street);

    asResident($other, $otherToken)->getJson(route('world.instances.snapshot', $streetInstance))
        ->assertOk()
        ->assertJsonPath('cinema', null);
});

test('switching the screen off darkens it for everyone at once and withholds the programme', function () {
    app(ScheduleScreening::class)(film(), false, $this->admin);
    $resident = resident();
    ['token' => $token, 'instance' => $instance] = enterWorld($resident, $this->cinema);

    app(SetWorldFlag::class)(WorldFlag::Cinema, false, $this->admin, 'Wrong reel');

    Event::assertDispatched(CinemaChanged::class, fn (CinemaChanged $event): bool => $event->instanceId === $instance->id
        && $event->cinema === ['open' => false, 'screenings' => []]);
    asResident($resident, $token)->getJson(route('world.instances.snapshot', $instance))
        ->assertJsonPath('cinema', ['open' => false, 'screenings' => []]);
});

test('an admin queues a film from the booth, and a refused one leaves the programme alone', function () {
    $this->actingAs($this->admin);
    Http::fake(['www.googleapis.com/*' => Http::sequence()
        ->push(youTubeVideo())
        ->push(youTubeVideo(['status' => ['embeddable' => false]]))]);

    Livewire::test(Cinema::class)
        ->callAction('queue', data: ['video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])
        ->assertHasNoActionErrors()
        ->assertNotified('Queued: Decker: Unsealed, at 4:00 PM')
        ->callAction('playNow', data: ['video' => 'dQw4w9WgXcQ'])
        ->assertNotified("The VFA can't show that");

    expect(Screening::query()->sole())
        ->video_id->toBe('dQw4w9WgXcQ')
        ->duration_seconds->toBe(3723)
        ->scheduled_by->toBe($this->admin->id);
});

test('only admins reach the booth', function () {
    $this->actingAs(User::factory()->staff(StaffRole::Moderator)->create());

    $this->get(Cinema::getUrl())->assertForbidden();
});
