<?php

use App\Actions\World\JoinRoom;
use App\Domain\Economy\LedgerReason;
use App\Domain\Economy\Wallet;
use App\Domain\World\Clock;
use App\Domain\World\PositionStore;
use App\Domain\World\StoredPath;
use App\Events\Fishing\RareCatchLanded;
use App\Features\FishingEnabled;
use App\Features\WorldFlags;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    fakeWorldEvents();
    $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'America/New_York'));
    config([
        'hushfield.fishing.bite_after_ms' => [2000, 7000],
        'hushfield.fishing.reel_window_ms' => 1500,
        'hushfield.fishing.daily_cap' => 100,
        'hushfield.fishing.catches' => ['eel' => ['weight' => 1, 'rarity' => 'uncommon', 'coins' => 8]],
    ]);

    // test-pier has one fishing spot, at (8, 1); everyone arrives at (1, 1).
    $this->pier = importRoom('test-pier');
    $this->resident = resident();
    ['token' => $this->token] = enterWorld($this->resident, $this->pier);
    standOnPier(8, 1);
});

function standOnPier(int $x, int $y): void
{
    app(PositionStore::class)->put(test()->resident->id, StoredPath::standingAt($x, $y, Clock::nowMs()));
}

function castLine(): TestResponse
{
    return asResident(test()->resident, test()->token)->postJson(route('world.fishing.cast'));
}

function reelLine(string $castId): TestResponse
{
    return asResident(test()->resident, test()->token)->postJson(route('world.fishing.reel'), ['cast_id' => $castId]);
}

/**
 * Casts, then reels at the given offset from the bite (negative is before it).
 *
 * @return array{TestResponse, string} The reel's response and the cast's id.
 */
function castAndReel(int $msAfterBite): array
{
    $cast = castLine()->assertCreated()->json('cast');
    test()->travelTo(Carbon::createFromTimestampMs($cast['bite_at_ms'] + $msAfterBite));

    return [reelLine($cast['id']), $cast['id']];
}

function fishingPaid(): int
{
    return (int) LedgerEntry::query()->where('reason', LedgerReason::Fishing)->sum('amount');
}

test('a cast tells the client when it bites and until when a reel lands it, but not what is on the line', function () {
    $now = Clock::nowMs();

    $cast = castLine()->assertCreated()->assertJsonPath('protocol', 3)->json('cast');

    expect(array_keys($cast))->toBe(['id', 'bite_at_ms', 'reel_by_ms'])
        ->and($cast['bite_at_ms'])->toBeGreaterThanOrEqual($now + 2000)->toBeLessThanOrEqual($now + 7000)
        ->and($cast['reel_by_ms'] - $cast['bite_at_ms'])->toBe(1500);
});

test('casting is refused anywhere but beside a fishing spot', function () {
    // On the pier, but at the spawn and not the water's edge.
    standOnPier(1, 1);
    castLine()->assertStatus(422)->assertJsonPath('reason', 'fishing_too_far')->assertJsonPath('message', __('hushfield.fishing_too_far'));

    // A step away from the spot is close enough; two is not.
    standOnPier(7, 2);
    castLine()->assertCreated();
    standOnPier(6, 1);
    castLine()->assertStatus(422)->assertJsonPath('reason', 'fishing_too_far');

    // A room with no fishing spot at all.
    app(JoinRoom::class)($this->resident, importRoom('test-square'));
    castLine()->assertStatus(409)->assertJsonPath('reason', 'fishing_elsewhere')->assertJsonPath('message', __('hushfield.fishing_elsewhere'));

    // A session that is in no room.
    $wanderer = resident();
    asResident($wanderer, startSession($wanderer))->postJson(route('world.fishing.cast'))
        ->assertStatus(409)
        ->assertJsonPath('reason', 'fishing_elsewhere');
});

test('a reel inside the bite window lands the catch and pays it exactly once', function () {
    [$response, $castId] = castAndReel(1400);

    $response->assertOk()
        ->assertJsonPath('reel.outcome', 'landed')
        ->assertJsonPath('reel.catch.key', 'eel')
        ->assertJsonPath('reel.catch.name', __('hushfield.catches.eel.name'))
        ->assertJsonPath('reel.paid', 8)
        ->assertJsonPath('message', trans_choice('hushfield.fishing_landed', 8, ['coins' => 8]))
        ->assertJsonPath('wallet.balance', 8);

    $entry = LedgerEntry::query()->sole();
    expect($entry->reason)->toBe(LedgerReason::Fishing)
        ->and($entry->amount)->toBe(8)
        ->and($entry->idempotency_key)->toBe("fishing:{$castId}");

    // A replay, even once the window has long closed, gets the same answer and pays nothing more.
    $this->travel(30)->seconds();
    reelLine($castId)->assertOk()
        ->assertJsonPath('reel.outcome', 'landed')
        ->assertJsonPath('reel.paid', 8)
        ->assertJsonPath('wallet.balance', 8);

    expect(LedgerEntry::query()->count())->toBe(1);
});

test('the catch is the one rolled when the line went in', function () {
    $cast = castLine()->json('cast');

    // Had the catch been rolled at the reel, this table could only give a boot.
    config(['hushfield.fishing.catches' => [
        'eel' => ['weight' => 0, 'rarity' => 'uncommon', 'coins' => 8],
        'boot' => ['weight' => 1, 'rarity' => 'common', 'coins' => 1],
    ]]);
    $this->travelTo(Carbon::createFromTimestampMs($cast['bite_at_ms']));

    reelLine($cast['id'])->assertOk()->assertJsonPath('reel.catch.key', 'eel')->assertJsonPath('reel.paid', 8);
});

test('reeling before the bite or after the window pays nothing', function (int $msAfterBite, string $outcome) {
    [$response, $castId] = castAndReel($msAfterBite);

    $response->assertOk()
        ->assertJsonPath('reel.outcome', $outcome)
        ->assertJsonPath('reel.catch', null)
        ->assertJsonPath('reel.paid', 0)
        ->assertJsonPath('message', __("hushfield.fishing_{$outcome}"))
        ->assertJsonPath('wallet.balance', 0);

    // The line is out of the water: reeling it again changes nothing.
    reelLine($castId)->assertOk()->assertJsonPath('reel.outcome', $outcome);

    expect(LedgerEntry::query()->count())->toBe(0);
})->with([
    'early' => [-1, 'early'],
    'late' => [1501, 'late'],
]);

test('a line that is not in the water pays nothing', function () {
    reelLine((string) Str::uuid())->assertStatus(409)
        ->assertJsonPath('reason', 'fishing_no_line')
        ->assertJsonPath('message', __('hushfield.fishing_no_line'));

    // Casting again takes the first line out of the water.
    $first = castLine()->json('cast');
    $second = castLine()->json('cast');
    $this->travelTo(Carbon::createFromTimestampMs(max($first['bite_at_ms'], $second['bite_at_ms'])));
    reelLine($first['id'])->assertStatus(409)->assertJsonPath('reason', 'fishing_no_line');

    // So does leaving the room.
    app(JoinRoom::class)($this->resident, importRoom('test-square'));
    app(JoinRoom::class)($this->resident, $this->pier);
    reelLine($second['id'])->assertStatus(409)->assertJsonPath('reason', 'fishing_no_line');

    expect(LedgerEntry::query()->count())->toBe(0);
});

test('fishing pays up to the daily cap, then catches pay nothing until the next Hushfield day', function () {
    config(['hushfield.fishing.daily_cap' => 20]);

    castAndReel(0)[0]->assertJsonPath('reel.paid', 8);
    castAndReel(0)[0]->assertJsonPath('reel.paid', 8);

    // This one would take it to 24, so it pays the 4 left under the cap, and says so.
    castAndReel(0)[0]
        ->assertJsonPath('reel.outcome', 'landed')
        ->assertJsonPath('reel.paid', 4)
        ->assertJsonPath('message', trans_choice('hushfield.fishing_landed', 4, ['coins' => 4]).' '.__('hushfield.fishing_cap_reached'));

    castAndReel(0)[0]
        ->assertJsonPath('reel.outcome', 'landed')
        ->assertJsonPath('reel.catch.key', 'eel')
        ->assertJsonPath('reel.paid', 0)
        ->assertJsonPath('message', __('hushfield.fishing_capped'))
        ->assertJsonPath('wallet.balance', 20);

    expect(fishingPaid())->toBe(20)
        ->and(LedgerEntry::query()->count())->toBe(3);

    // Midnight in Hushfield (05:00 UTC), not in UTC, starts a fresh allowance.
    $this->travelTo(CarbonImmutable::parse('2026-10-25 23:59:00', 'America/New_York'));
    castAndReel(0)[0]->assertJsonPath('reel.paid', 0);
    $this->travelTo(CarbonImmutable::parse('2026-10-26 00:01:00', 'America/New_York'));
    castAndReel(0)[0]->assertJsonPath('reel.paid', 8)->assertJsonPath('wallet.balance', 28);
});

test('while fishing is switched off nothing can be cast or landed', function () {
    $cast = castLine()->json('cast');

    WorldFlags::set(FishingEnabled::class, false);

    castLine()->assertStatus(409)->assertJsonPath('reason', 'fishing_closed')->assertJsonPath('message', __('hushfield.fishing_closed'));
    $this->travelTo(Carbon::createFromTimestampMs($cast['bite_at_ms']));
    reelLine($cast['id'])->assertStatus(409)->assertJsonPath('reason', 'fishing_closed');

    expect(LedgerEntry::query()->count())->toBe(0)
        ->and(app(Wallet::class)->balance($this->resident))->toBe(0);

    WorldFlags::set(FishingEnabled::class, true);

    castAndReel(0)[0]->assertJsonPath('reel.paid', 8);
});

test('the room hears about rare catches, and only those', function () {
    castAndReel(0);
    Event::assertNotDispatched(RareCatchLanded::class);

    config(['hushfield.fishing.catches' => ['letter' => ['weight' => 1, 'rarity' => 'rare', 'coins' => 25]]]);
    castAndReel(0);

    Event::assertDispatched(RareCatchLanded::class, fn (RareCatchLanded $event): bool => $event->userId === $this->resident->id
        && $event->message === __('hushfield.fishing_rare_landed', ['name' => $this->resident->username, 'catch' => lcfirst(__('hushfield.catches.letter.name'))]));
});
