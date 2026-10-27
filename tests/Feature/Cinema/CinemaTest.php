<?php

use App\Actions\Cinema\CancelScreening;
use App\Actions\Cinema\ScheduleScreening;
use App\Actions\Cinema\SetHouseLights;
use App\Actions\World\SetWorldFlag;
use App\Domain\Cinema\Film;
use App\Domain\Cinema\FilmRefused;
use App\Domain\Cinema\HouseLights;
use App\Domain\Cinema\YouTube;
use App\Domain\Identity\StaffRole;
use App\Events\World\CinemaChanged;
use App\Features\WorldFlag;
use App\Filament\Pages\Cinema;
use App\Models\Screening;
use App\Models\Theater;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    fakeWorldEvents();
    $this->travelTo(CarbonImmutable::parse('2026-10-25 20:00:00', 'UTC'));
    config(['hushfield.cinema.theaters' => ['test-square' => 'Screen 1', 'test-market' => 'Screen 2'], 'hushfield.cinema.intermission_seconds' => 60, 'hushfield.cinema.youtube_key' => 'test-key']);
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
    return Screening::query()->where('theater', 'test-square')->orderBy('starts_at')->get()->map(fn (Screening $screening): array => [
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

    $schedule(film('aaaaaaaaaaa', 600), 'test-square', false, $this->admin);
    $schedule(film('bbbbbbbbbbb', 90), 'test-square', false, $this->admin);
    $schedule(film('ccccccccccc', 30), 'test-square', false, $this->admin);

    expect(programme())->toBe([
        ['title' => 'Film aaaaaaaaaaa', 'starts' => '20:00:00', 'ends' => '20:10:00'],
        ['title' => 'Film bbbbbbbbbbb', 'starts' => '20:11:00', 'ends' => '20:12:30'],
        ['title' => 'Film ccccccccccc', 'starts' => '20:13:30', 'ends' => '20:14:00'],
    ]);
});

test('playing a film now cuts the one showing short and moves the queue back behind it', function () {
    $schedule = app(ScheduleScreening::class);
    $schedule(film('aaaaaaaaaaa', 600), 'test-square', false, $this->admin);
    $schedule(film('bbbbbbbbbbb', 300), 'test-square', false, $this->admin);
    $this->travel(2)->minutes();

    $schedule(film('ccccccccccc', 120), 'test-square', true, $this->admin);

    expect(programme())->toBe([
        ['title' => 'Film aaaaaaaaaaa', 'starts' => '20:00:00', 'ends' => '20:02:00'],
        ['title' => 'Film ccccccccccc', 'starts' => '20:02:00', 'ends' => '20:04:00'],
        ['title' => 'Film bbbbbbbbbbb', 'starts' => '20:05:00', 'ends' => '20:10:00'],
    ]);
});

test('premieres keep their time, cut short the film they meet, and the queue flows around them in its order', function () {
    $schedule = app(ScheduleScreening::class);
    $schedule(film('aaaaaaaaaaa', 600), 'test-square', false, $this->admin);

    $schedule(film('ppppppppppp', 300), 'test-square', false, $this->admin, now()->addMinutes(5));
    $schedule(film('bbbbbbbbbbb', 120), 'test-square', false, $this->admin);
    $schedule(film('qqqqqqqqqqq', 600), 'test-square', false, $this->admin, now()->addMinutes(30));
    // Sixteen minutes would end at 20:30 with no intermission before the premiere at 20:30, so it goes after it.
    $schedule(film('ccccccccccc', 960), 'test-square', false, $this->admin);
    // Short enough for the gap before 20:30, but the queue keeps its order.
    $schedule(film('ddddddddddd', 60), 'test-square', false, $this->admin);

    expect(programme())->toBe([
        ['title' => 'Film aaaaaaaaaaa', 'starts' => '20:00:00', 'ends' => '20:05:00'],
        ['title' => 'Film ppppppppppp', 'starts' => '20:05:00', 'ends' => '20:10:00'],
        ['title' => 'Film bbbbbbbbbbb', 'starts' => '20:11:00', 'ends' => '20:13:00'],
        ['title' => 'Film qqqqqqqqqqq', 'starts' => '20:30:00', 'ends' => '20:40:00'],
        ['title' => 'Film ccccccccccc', 'starts' => '20:41:00', 'ends' => '20:57:00'],
        ['title' => 'Film ddddddddddd', 'starts' => '20:58:00', 'ends' => '20:59:00'],
    ]);

    // Removing a queued film moves the queue up into the gap it leaves, but never moves a premiere.
    app(CancelScreening::class)(Screening::query()->where('video_id', 'bbbbbbbbbbb')->sole(), $this->admin);

    expect(array_map(fn (array $row): string => "{$row['title']} {$row['starts']}", programme()))->toBe([
        'Film aaaaaaaaaaa 20:00:00',
        'Film ppppppppppp 20:05:00',
        'Film ccccccccccc 20:11:00',
        'Film ddddddddddd 20:28:00',
        'Film qqqqqqqqqqq 20:30:00',
    ]);
});

test('a premiere must be in the future and clear of every other premiere', function () {
    $schedule = app(ScheduleScreening::class);
    $schedule(film('ppppppppppp', 600), 'test-square', false, $this->admin, now()->addHour());

    expect(fn () => $schedule(film('qqqqqqqqqqq', 600), 'test-square', false, $this->admin, now()->subMinute()))
        ->toThrow(FilmRefused::class, 'in the future')
        // Ending at 21:00 leaves no intermission before 21:00.
        ->and(fn () => $schedule(film('qqqqqqqqqqq', 3000), 'test-square', false, $this->admin, now()->addMinutes(10)))
        ->toThrow(FilmRefused::class, 'premiere of "Film ppppppppppp" at 5:00 PM')
        ->and(fn () => $schedule(film('qqqqqqqqqqq', 60), 'test-square', false, $this->admin, now()->addMinutes(70)))
        ->toThrow(FilmRefused::class, 'Film ppppppppppp');

    $schedule(film('rrrrrrrrrrr', 60), 'test-square', false, $this->admin, now()->addMinutes(71));
    $schedule(film('sssssssssss', 60), 'test-street', false, $this->admin, now()->addHour());

    expect(Screening::query()->count())->toBe(3);
});

test('each theater has its own programme', function () {
    $market = importRoom('test-market');
    $inMarket = enterWorld(resident(), $market)['instance'];

    app(ScheduleScreening::class)(film('aaaaaaaaaaa', 600), 'test-square', false, $this->admin);
    $there = app(ScheduleScreening::class)(film('bbbbbbbbbbb', 300), 'test-market', false, $this->admin);

    expect(programme())->toHaveCount(1)
        ->and($there->starts_at->format('H:i:s'))->toBe('20:00:00');
    Event::assertDispatched(CinemaChanged::class, fn (CinemaChanged $event): bool => $event->instanceId === $inMarket->id
        && array_column($event->cinema['screenings'], 'title') === ['Film bbbbbbbbbbb']);
});

test('house lights change for everyone in the theater at once', function () {
    $resident = resident();
    ['token' => $token, 'instance' => $instance] = enterWorld($resident, $this->cinema);

    expect(app(SetHouseLights::class)('test-square', HouseLights::Dark, $this->admin))->toBeTrue()
        ->and(app(SetHouseLights::class)('test-square', HouseLights::Dark, $this->admin))->toBeFalse();

    Event::assertDispatchedTimes(CinemaChanged::class, 1);
    Event::assertDispatched(CinemaChanged::class, fn (CinemaChanged $event): bool => $event->instanceId === $instance->id && $event->cinema['lights'] === 'dark');
    asResident($resident, $token)->getJson(route('world.instances.snapshot', $instance))->assertJsonPath('cinema.lights', 'dark');
    expect(Activity::query()->where('event', 'lights.set')->sole()->description)->toBe('Screen 1 house lights: Dark');
});

test('stopping the film showing moves the queue up; removing a queued one gives its slot to the next', function () {
    $schedule = app(ScheduleScreening::class);
    $cancel = app(CancelScreening::class);
    $first = $schedule(film('aaaaaaaaaaa', 600), 'test-square', false, $this->admin);
    $second = $schedule(film('bbbbbbbbbbb', 300), 'test-square', false, $this->admin);
    $schedule(film('ccccccccccc', 120), 'test-square', false, $this->admin);
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

    $screening = app(ScheduleScreening::class)(film(), 'test-square', false, $this->admin);

    Event::assertDispatchedTimes(CinemaChanged::class, 2);
    foreach ([$first->id, $second->id] as $instanceId) {
        Event::assertDispatched(CinemaChanged::class, fn (CinemaChanged $event): bool => $event->instanceId === $instanceId
            && $event->cinema === ['open' => true, 'lights' => 'auto', 'screenings' => [$screening->toPayload()]]);
    }
});

test('the cinema snapshot carries the programme, in server milliseconds, and other rooms carry none', function () {
    $screening = app(ScheduleScreening::class)(film('aaaaaaaaaaa', 600), 'test-square', false, $this->admin);
    $resident = resident();
    ['token' => $token, 'instance' => $instance] = enterWorld($resident, $this->cinema);

    asResident($resident, $token)->getJson(route('world.instances.snapshot', $instance))
        ->assertOk()
        ->assertJsonPath('cinema', ['open' => true, 'lights' => 'auto', 'screenings' => [[
            'id' => $screening->id,
            'video_id' => 'aaaaaaaaaaa',
            'title' => 'Film aaaaaaaaaaa',
            'premiere' => false,
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
    app(ScheduleScreening::class)(film(), 'test-square', false, $this->admin);
    $resident = resident();
    ['token' => $token, 'instance' => $instance] = enterWorld($resident, $this->cinema);

    app(SetWorldFlag::class)(WorldFlag::Cinema, false, $this->admin, 'Wrong reel');

    Event::assertDispatched(CinemaChanged::class, fn (CinemaChanged $event): bool => $event->instanceId === $instance->id
        && $event->cinema === ['open' => false, 'lights' => 'auto', 'screenings' => []]);
    asResident($resident, $token)->getJson(route('world.instances.snapshot', $instance))
        ->assertJsonPath('cinema', ['open' => false, 'lights' => 'auto', 'screenings' => []]);
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
        ->theater->toBe('test-square')
        ->video_id->toBe('dQw4w9WgXcQ')
        ->duration_seconds->toBe(3723)
        ->scheduled_by->toBe($this->admin->id);
});

test('from the booth an admin schedules a premiere in Hushfield time, in the theater they picked, and sets its lights', function () {
    $this->actingAs($this->admin);
    Http::fake(['www.googleapis.com/*' => Http::response(youTubeVideo())]);

    Livewire::withQueryParams(['theater' => 'test-market'])->test(Cinema::class)
        ->assertSee('Screen 2')
        // 20:00 UTC is 4 PM in Hushfield; the premiere is at 9 PM there.
        ->callAction('premiere', data: ['video' => 'dQw4w9WgXcQ', 'starts_at' => '2026-10-25 21:00:00'])
        ->assertHasNoActionErrors()
        ->assertNotified('Premiere: Decker: Unsealed, at Sun 25 Oct, 9:00 PM')
        ->callAction('lights', data: ['lights' => 'dim'])
        ->assertNotified('Screen 2 house lights: Dim');

    expect(Screening::query()->sole()->only('theater', 'premiere'))->toBe(['theater' => 'test-market', 'premiere' => true])
        ->and(Screening::query()->sole()->starts_at->toIso8601String())->toBe('2026-10-26T01:00:00+00:00')
        ->and(Theater::lights('test-market'))->toBe(HouseLights::Dim)
        ->and(Theater::lights('test-square'))->toBe(HouseLights::Auto);
});

test('only admins reach the booth', function () {
    $this->actingAs(User::factory()->staff(StaffRole::Moderator)->create());

    $this->get(Cinema::getUrl())->assertForbidden();
});
