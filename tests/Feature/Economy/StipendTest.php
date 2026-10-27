<?php

use App\Domain\Economy\LedgerReason;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->resident = resident();
    $this->token = startSession($this->resident);
});

function claimStipend()
{
    return asResident(test()->resident, test()->token)->postJson(route('world.stipend.claim'));
}

test('the daily stipend is credited once per Hushfield day', function () {
    // 23:30 in Hushfield (America/New_York) on the 25th, but already the 26th in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-26 03:30:00', 'UTC'));

    claimStipend()
        ->assertCreated()
        ->assertJsonPath('protocol', 3)
        ->assertJsonPath('message', __('hushfield.stipend_granted'))
        ->assertJsonPath('wallet.balance', 25)
        ->assertJsonPath('wallet.stipend.available', false)
        ->assertJsonPath('wallet.stipend.next_at', '2026-10-26T00:00:00-04:00');

    claimStipend()
        ->assertStatus(409)
        ->assertJsonPath('reason', 'stipend_claimed');

    // Midnight in Hushfield; still the same UTC day as the first claim.
    $this->travelTo(CarbonImmutable::parse('2026-10-26 04:30:00', 'UTC'));

    claimStipend()->assertCreated()->assertJsonPath('wallet.balance', 50);

    expect(LedgerEntry::query()->where('reason', LedgerReason::Stipend)->count())->toBe(2);
});

test('the wallet shows the stipend as available until it is claimed', function () {
    asResident($this->resident, $this->token)->getJson(route('world.wallet'))
        ->assertOk()
        ->assertJsonPath('wallet.balance', 0)
        ->assertJsonPath('wallet.stipend.amount', 25)
        ->assertJsonPath('wallet.stipend.available', true)
        ->assertJsonPath('wallet.inventory', []);

    claimStipend()->assertCreated();

    // The balance read above was cached; the claim must have dropped it.
    asResident($this->resident, $this->token)->getJson(route('world.wallet'))
        ->assertJsonPath('wallet.balance', 25)
        ->assertJsonPath('wallet.stipend.available', false);
});

test('economy requests need a current world session', function () {
    $this->actingAs($this->resident)->postJson(route('world.stipend.claim'))
        ->assertStatus(409)
        ->assertJsonPath('reason', 'session_moved');

    expect(LedgerEntry::query()->count())->toBe(0);
});

test('ledger entries cannot be changed or deleted', function () {
    claimStipend()->assertCreated();
    $entry = LedgerEntry::query()->sole();

    expect(fn () => $entry->update(['amount' => 1_000_000]))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and($entry->fresh()->amount)->toBe(25);
});
