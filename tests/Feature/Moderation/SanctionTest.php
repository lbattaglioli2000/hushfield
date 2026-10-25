<?php

use App\Actions\Moderation\IssueSanction;
use App\Actions\Moderation\LiftSanction;
use App\Actions\Moderation\ResolveReport;
use App\Domain\Moderation\ReportResolution;
use App\Domain\Moderation\ReportStatus;
use App\Domain\Moderation\SanctionType;
use App\Domain\Presence\InstanceMembers;
use App\Events\Moderation\PlayerWarned;
use App\Events\Moderation\WorldAccessRevoked;
use App\Events\World\PlayerLeft;
use App\Exceptions\ModerationActionRefused;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\Sanction;
use App\Models\User;
use App\Models\WorldSession;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    fakeWorldEvents();
    $this->room = importRoom();
    $this->staff = User::factory()->staff()->create();
    $this->target = resident();
    ['token' => $this->token, 'instance' => $this->instance] = enterWorld($this->target, $this->room);
    $this->members = app(InstanceMembers::class);
});

test('a ban revokes world access immediately', function () {
    $endsAt = now()->addDays(3)->startOfSecond();

    $sanction = app(IssueSanction::class)($this->target, SanctionType::Ban, 'Harassment', $endsAt, $this->staff);

    expect($this->target->fresh()->banned_until->equalTo($endsAt))->toBeTrue()
        ->and($sanction->type)->toBe(SanctionType::Ban)
        ->and($sanction->issued_by)->toBe($this->staff->id)
        ->and($this->members->contains($this->instance->id, $this->target->id))->toBeFalse()
        ->and(WorldSession::query()->where('user_id', $this->target->id)->exists())->toBeFalse()
        ->and($this->target->fresh()->last_room_instance_id)->toBeNull();

    Event::assertDispatched(WorldAccessRevoked::class, fn (WorldAccessRevoked $event): bool => $event->broadcastOn()->name === "private-world-session.{$this->token}");
    Event::assertDispatched(PlayerLeft::class, fn (PlayerLeft $event): bool => $event->broadcastOn()->name === "presence-room.{$this->instance->id}"
        && $event->userId === $this->target->id);

    $this->assertDatabaseHas('activity_log', ['log_name' => 'moderation', 'event' => 'sanction.ban', 'causer_id' => $this->staff->id, 'subject_id' => $this->target->id]);

    $this->actingAs($this->target)->postJson(route('world.session.start'))->assertForbidden()->assertJsonPath('reason', 'banned');
    $this->get(route('play'))->assertOk();
});

test('a banned resident\'s old token is refused as banned', function () {
    app(IssueSanction::class)($this->target, SanctionType::Ban, 'Spam', null, $this->staff);

    expect($this->target->fresh()->isPermanentlyBanned())->toBeTrue();

    asResident($this->target, $this->token)
        ->postJson(route('world.instances.move', $this->instance), ['x' => 2, 'y' => 2])
        ->assertForbidden()
        ->assertJsonPath('reason', 'banned');
});

test('lifting the only ban restores access', function () {
    $ban = app(IssueSanction::class)($this->target, SanctionType::Ban, 'Spam', now()->addDay(), $this->staff);

    app(LiftSanction::class)($ban, $this->staff, 'Appeal accepted');

    expect($this->target->fresh()->banned_until)->toBeNull()
        ->and($ban->fresh()->lifted_at)->not->toBeNull()
        ->and($ban->fresh()->lifted_by)->toBe($this->staff->id);

    $this->actingAs($this->target->fresh())->postJson(route('world.session.start'))->assertCreated();
    $this->assertDatabaseHas('activity_log', ['event' => 'sanction.lifted', 'subject_id' => $this->target->id]);
});

test('lifting one ban keeps the longest remaining one', function () {
    $short = app(IssueSanction::class)($this->target, SanctionType::Ban, 'Spam', now()->addDay(), $this->staff);
    $longEnd = now()->addDays(10)->startOfSecond();
    app(IssueSanction::class)($this->target, SanctionType::Ban, 'Spam again', $longEnd, $this->staff);
    $permanent = app(IssueSanction::class)($this->target, SanctionType::Ban, 'Threats', null, $this->staff);

    app(LiftSanction::class)($permanent, $this->staff, 'Too harsh');
    expect($this->target->fresh()->banned_until->equalTo($longEnd))->toBeTrue();

    app(LiftSanction::class)($short, $this->staff, 'Mistake');
    expect($this->target->fresh()->banned_until->equalTo($longEnd))->toBeTrue();
});

test('lifting a ban while a permanent ban remains keeps it permanent', function () {
    $temporary = app(IssueSanction::class)($this->target, SanctionType::Ban, 'Spam', now()->addDay(), $this->staff);
    app(IssueSanction::class)($this->target, SanctionType::Ban, 'Threats', null, $this->staff);

    app(LiftSanction::class)($temporary, $this->staff, 'Superseded');

    expect($this->target->fresh()->isPermanentlyBanned())->toBeTrue();
});

test('a shorter ban never shortens a longer one', function () {
    app(IssueSanction::class)($this->target, SanctionType::Ban, 'Threats', null, $this->staff);
    app(IssueSanction::class)($this->target, SanctionType::Ban, 'Spam', now()->addDay(), $this->staff);

    expect($this->target->fresh()->isPermanentlyBanned())->toBeTrue();

    $longEnd = now()->addDays(10)->startOfSecond();
    $other = resident();
    app(IssueSanction::class)($other, SanctionType::Ban, 'Spam', $longEnd, $this->staff);
    app(IssueSanction::class)($other, SanctionType::Ban, 'Spam again', now()->addDay(), $this->staff);

    expect($other->fresh()->banned_until->equalTo($longEnd))->toBeTrue();
});

test('staff cannot be sanctioned', function (SanctionType $type) {
    $colleague = User::factory()->staff()->create();

    expect(fn () => app(IssueSanction::class)($colleague, $type, 'Oops', now()->addDay(), $this->staff))
        ->toThrow(ModerationActionRefused::class);

    expect(Sanction::query()->count())->toBe(0)
        ->and($colleague->fresh()->banned_until)->toBeNull();
})->with(SanctionType::cases());

test('lifting a mute lets the resident chat again', function () {
    $mute = app(IssueSanction::class)($this->target, SanctionType::Mute, 'Spam', now()->addHour(), $this->staff);
    expect($this->target->isMuted())->toBeTrue();

    app(LiftSanction::class)($mute, $this->staff, 'Calmed down');

    expect($this->target->isMuted())->toBeFalse()
        ->and($this->target->fresh()->banned_until)->toBeNull();
});

function openReport(User $reporter, User $reported, int $instanceId): ChatReport
{
    $message = ChatMessage::factory()->create(['user_id' => $reported->id, 'room_instance_id' => $instanceId]);

    return ChatReport::factory()->create([
        'reporter_id' => $reporter->id,
        'reported_user_id' => $reported->id,
        'chat_message_id' => $message->id,
        'room_instance_id' => $instanceId,
    ]);
}

test('resolving a report records the outcome', function (ReportResolution $resolution) {
    $report = openReport(resident(), $this->target, $this->instance->id);

    app(ResolveReport::class)($report, $resolution, $this->staff, 'Reviewed', now()->addDay());

    $report->refresh();

    expect($report->status)->toBe(ReportStatus::Resolved)
        ->and($report->resolution)->toBe($resolution)
        ->and($report->resolved_by)->toBe($this->staff->id)
        ->and($report->resolved_at)->not->toBeNull();

    $this->assertDatabaseHas('activity_log', ['event' => 'report.resolved', 'subject_id' => $report->id, 'subject_type' => ChatReport::class]);
})->with(ReportResolution::cases());

test('dismissing a report sanctions nobody', function () {
    $report = openReport(resident(), $this->target, $this->instance->id);

    app(ResolveReport::class)($report, ReportResolution::Dismissed, $this->staff, 'Fine');

    expect(Sanction::query()->count())->toBe(0)
        ->and($this->members->contains($this->instance->id, $this->target->id))->toBeTrue();
    Event::assertNotDispatched(PlayerWarned::class);
    Event::assertNotDispatched(WorldAccessRevoked::class);
});

test('resolving a report as warned warns the player in the world', function () {
    $report = openReport(resident(), $this->target, $this->instance->id);

    app(ResolveReport::class)($report, ReportResolution::Warned, $this->staff, 'Be kind');

    expect(Sanction::query()->sole())->type->toBe(SanctionType::Warning)->chat_report_id->toBe($report->id)
        ->and($this->target->fresh()->isMuted())->toBeFalse()
        ->and($this->target->fresh()->isBanned())->toBeFalse();
    Event::assertDispatched(PlayerWarned::class, fn (PlayerWarned $event): bool => $event->broadcastOn()->name === "private-world-session.{$this->token}");
});

test('resolving a report as muted mutes the player', function () {
    $report = openReport(resident(), $this->target, $this->instance->id);

    app(ResolveReport::class)($report, ReportResolution::Muted, $this->staff, 'Spam', now()->addDay());

    expect(Sanction::query()->sole())->type->toBe(SanctionType::Mute)->chat_report_id->toBe($report->id)
        ->and($this->target->fresh()->isMuted())->toBeTrue()
        ->and($this->members->contains($this->instance->id, $this->target->id))->toBeTrue();
    Event::assertNotDispatched(WorldAccessRevoked::class);
});

test('resolving a report as banned bans the player and removes them from the world', function () {
    $report = openReport(resident(), $this->target, $this->instance->id);

    app(ResolveReport::class)($report, ReportResolution::Banned, $this->staff, 'Threats', now()->addDay());

    expect(Sanction::query()->sole())->type->toBe(SanctionType::Ban)->chat_report_id->toBe($report->id)
        ->and($this->target->fresh()->isBanned())->toBeTrue()
        ->and($this->members->contains($this->instance->id, $this->target->id))->toBeFalse();
    Event::assertDispatched(WorldAccessRevoked::class, fn (WorldAccessRevoked $event): bool => $event->broadcastOn()->name === "private-world-session.{$this->token}");
});

test('a report can only be resolved once', function () {
    $report = openReport(resident(), $this->target, $this->instance->id);
    $staleCopy = $report->fresh();

    app(ResolveReport::class)($report, ReportResolution::Muted, $this->staff, 'Spam', now()->addDay());

    expect(fn () => app(ResolveReport::class)($staleCopy, ReportResolution::Banned, $this->staff, 'Spam', now()->addDay()))
        ->toThrow(ModerationActionRefused::class);

    expect(Sanction::query()->pluck('type')->all())->toBe([SanctionType::Mute])
        ->and($report->fresh()->resolution)->toBe(ReportResolution::Muted)
        ->and($this->target->fresh()->isBanned())->toBeFalse();
});

test('a report against staff is not resolved when the sanction is refused', function () {
    $colleague = User::factory()->staff()->create();
    $report = openReport(resident(), $colleague, $this->instance->id);

    expect(fn () => app(ResolveReport::class)($report, ReportResolution::Muted, $this->staff, 'Spam', now()->addDay()))
        ->toThrow(ModerationActionRefused::class);

    expect($report->fresh()->status)->toBe(ReportStatus::Open)
        ->and(Sanction::query()->count())->toBe(0);
});

test('a warning restricts nothing and is not active afterwards', function () {
    $warning = app(IssueSanction::class)($this->target, SanctionType::Warning, 'Be kind', now()->addYear(), $this->staff);

    expect($warning->ends_at->lte(now()))->toBeTrue()
        ->and($this->target->fresh()->isBanned())->toBeFalse()
        ->and($this->target->isMuted())->toBeFalse();
});

test('a warning for someone not in the world is recorded without a broadcast', function () {
    $offline = resident();

    app(IssueSanction::class)($offline, SanctionType::Warning, 'Be kind', null, $this->staff);

    expect(Sanction::query()->where('user_id', $offline->id)->count())->toBe(1);
    Event::assertNotDispatched(PlayerWarned::class);
});
