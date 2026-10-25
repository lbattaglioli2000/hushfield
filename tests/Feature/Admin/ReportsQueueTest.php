<?php

use App\Domain\Moderation\ReportResolution;
use App\Domain\Moderation\ReportStatus;
use App\Domain\Moderation\SanctionType;
use App\Filament\Resources\ChatReports\Pages\ViewChatReport;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\Sanction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    fakeWorldEvents();
    $this->moderator = User::factory()->staff()->create();
    $this->actingAs($this->moderator);
});

function reportAgainst(User $reported): ChatReport
{
    $message = ChatMessage::factory()->create(['user_id' => $reported->id]);

    return ChatReport::factory()->create([
        'reported_user_id' => $reported->id,
        'chat_message_id' => $message->id,
        'room_instance_id' => $message->room_instance_id,
    ]);
}

test('a moderator bans from a report', function () {
    $player = resident();
    $report = reportAgainst($player);

    Livewire::test(ViewChatReport::class, ['record' => $report->getRouteKey()])
        ->assertSee($report->chatMessage->body)
        ->callAction('resolve_banned', data: ['reason' => 'Threats'])
        ->assertHasNoActionErrors()
        ->assertNotified('Report resolved: banned');

    expect($report->fresh()->status)->toBe(ReportStatus::Resolved)
        ->and($report->fresh()->resolved_by)->toBe($this->moderator->id)
        ->and(Sanction::query()->sole()->only(['user_id', 'type', 'chat_report_id']))
        ->toBe(['user_id' => $player->id, 'type' => SanctionType::Ban, 'chat_report_id' => $report->id])
        ->and($player->fresh()->isPermanentlyBanned())->toBeTrue();
});

test('dismissing a report issues no sanction', function () {
    $report = reportAgainst(resident());

    Livewire::test(ViewChatReport::class, ['record' => $report->getRouteKey()])
        ->callAction('resolve_dismissed', data: ['reason' => 'Not abusive'])
        ->assertHasNoActionErrors();

    expect($report->fresh()->status)->toBe(ReportStatus::Resolved)
        ->and(Sanction::query()->count())->toBe(0);
});

test('a report against staff can be dismissed but not used to sanction them', function () {
    $colleague = User::factory()->staff()->create();
    $report = reportAgainst($colleague);

    Livewire::test(ViewChatReport::class, ['record' => $report->getRouteKey()])
        ->assertActionVisible('resolve_dismissed')
        ->assertActionHidden('resolve_warned')
        ->assertActionHidden('resolve_muted')
        ->assertActionHidden('resolve_banned');

    expect($this->moderator->can('resolveAs', [$report, ReportResolution::Banned]))->toBeFalse()
        ->and($this->moderator->can('resolveAs', [$report, ReportResolution::Dismissed]))->toBeTrue();
});

test('resolved reports offer no resolve actions', function () {
    $report = reportAgainst(resident());
    $report->forceFill(['status' => ReportStatus::Resolved])->save();

    Livewire::test(ViewChatReport::class, ['record' => $report->getRouteKey()])
        ->assertActionHidden('resolve_dismissed')
        ->assertActionHidden('resolve_banned');
});
