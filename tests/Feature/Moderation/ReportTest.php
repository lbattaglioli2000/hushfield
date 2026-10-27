<?php

use App\Domain\Moderation\ReportReason;
use App\Domain\Moderation\ReportStatus;
use App\Models\ChatMessage;
use App\Models\ChatReport;

beforeEach(function () {
    fakeWorldEvents();
    $this->room = importRoom();
    $this->reporter = resident();
    $this->reported = resident();
    ['token' => $this->token, 'instance' => $this->instance] = enterWorld($this->reporter, $this->room);
    enterWorld($this->reported, $this->room);
});

function fileReport(array $data)
{
    return asResident(test()->reporter, test()->token)->postJson(route('world.reports.store'), $data);
}

test('reporting a chat bubble links the message', function () {
    $message = ChatMessage::factory()->create(['user_id' => $this->reported->id, 'room_instance_id' => $this->instance->id]);

    fileReport([
        'reported_user_id' => $this->reported->id,
        'chat_message_id' => $message->id,
        'reason' => 'harassment',
    ])->assertCreated()
        ->assertJsonPath('protocol', 3)
        ->assertJsonPath('message', __('hushfield.report_sent'));

    $report = ChatReport::query()->sole();

    expect($report->reporter_id)->toBe($this->reporter->id)
        ->and($report->reported_user_id)->toBe($this->reported->id)
        ->and($report->chat_message_id)->toBe($message->id)
        ->and($report->room_instance_id)->toBe($this->instance->id)
        ->and($report->reason)->toBe(ReportReason::Harassment)
        ->and($report->status)->toBe(ReportStatus::Open);
});

test('reporting an avatar attaches the reported resident\'s latest line in that instance', function () {
    $elsewhere = $this->room->instances()->create(['number' => 2, 'is_open' => true]);
    ChatMessage::factory()->create(['user_id' => $this->reported->id, 'room_instance_id' => $this->instance->id, 'body' => 'older']);
    $latest = ChatMessage::factory()->create(['user_id' => $this->reported->id, 'room_instance_id' => $this->instance->id, 'body' => 'latest here']);
    ChatMessage::factory()->create(['user_id' => $this->reported->id, 'room_instance_id' => $elsewhere->id, 'body' => 'other instance']);
    ChatMessage::factory()->create(['user_id' => $this->reporter->id, 'room_instance_id' => $this->instance->id, 'body' => 'reporter line']);

    fileReport([
        'reported_user_id' => $this->reported->id,
        'room_instance_id' => $this->instance->id,
        'reason' => 'spam',
    ])->assertCreated();

    expect(ChatReport::query()->sole()->chat_message_id)->toBe($latest->id);
});

test('a message must belong to the reported resident', function () {
    $someoneElses = ChatMessage::factory()->create(['user_id' => $this->reporter->id, 'room_instance_id' => $this->instance->id]);

    fileReport([
        'reported_user_id' => $this->reported->id,
        'chat_message_id' => $someoneElses->id,
        'reason' => 'spam',
    ])->assertUnprocessable()->assertJsonValidationErrors('chat_message_id');

    expect(ChatReport::query()->count())->toBe(0);
});

test('reports need a known resident and reason', function (array $data, string $error) {
    fileReport($data)->assertUnprocessable()->assertOnlyJsonValidationErrors([$error]);

    expect(ChatReport::query()->count())->toBe(0);
})->with([
    // Bound closures run after beforeEach, so the otherwise valid fields can name its residents.
    'unknown resident' => [fn (): array => ['reported_user_id' => 999999, 'reason' => 'spam'], 'reported_user_id'],
    'unknown reason' => [fn (): array => ['reported_user_id' => $this->reported->id, 'reason' => 'boring'], 'reason'],
]);

test('residents cannot report themselves', function () {
    fileReport(['reported_user_id' => $this->reporter->id, 'reason' => 'other'])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'report_self')
        ->assertJsonPath('message', __('hushfield.report_self'));

    expect(ChatReport::query()->count())->toBe(0);
});

test('residents can file 5 reports an hour', function () {
    foreach (range(1, 5) as $attempt) {
        fileReport(['reported_user_id' => $this->reported->id, 'reason' => 'spam'])->assertCreated();
    }

    fileReport(['reported_user_id' => $this->reported->id, 'reason' => 'spam'])
        ->assertTooManyRequests()
        ->assertExactJson(['protocol' => 3, 'reason' => 'report_too_many', 'message' => __('hushfield.report_too_many')]);

    expect(ChatReport::query()->count())->toBe(5);

    $this->travel(61)->minutes();

    fileReport(['reported_user_id' => $this->reported->id, 'reason' => 'spam'])->assertCreated();
});
