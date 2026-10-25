<?php

use App\Models\ChatMessage;
use App\Models\ChatReport;

test('chat older than 30 days is pruned unless it was reported', function () {
    $this->freezeTime();
    $instance = importRoom()->instances()->firstOrFail();
    $old = ChatMessage::factory()->create(['room_instance_id' => $instance->id, 'created_at' => now()->subDays(31)]);
    $oldReported = ChatMessage::factory()->create(['room_instance_id' => $instance->id, 'created_at' => now()->subDays(31)]);
    $recent = ChatMessage::factory()->create(['room_instance_id' => $instance->id, 'created_at' => now()->subDays(29)]);
    ChatReport::factory()->create([
        'reported_user_id' => $oldReported->user_id,
        'chat_message_id' => $oldReported->id,
        'room_instance_id' => $instance->id,
    ]);
    $before = ChatMessage::query()->pluck('id')->diff([$old->id])->sort()->values()->all();

    $this->artisan('model:prune', ['--model' => [ChatMessage::class]])->assertSuccessful();

    expect(ChatMessage::query()->find($old->id))->toBeNull()
        ->and(ChatMessage::query()->find($oldReported->id))->not->toBeNull()
        ->and(ChatMessage::query()->find($recent->id))->not->toBeNull()
        ->and(ChatMessage::query()->pluck('id')->sort()->values()->all())->toBe($before);
});

test('a message comes with up to the given number of lines either side of it in its instance', function () {
    $instance = importRoom()->instances()->firstOrFail();
    $elsewhere = ChatMessage::factory()->create();
    $lines = ChatMessage::factory()->count(7)->create(['room_instance_id' => $instance->id]);

    $context = ChatMessage::around($lines[3], 2);

    expect($context->pluck('id')->all())->toBe($lines->slice(1, 5)->pluck('id')->all())
        ->and($context->pluck('id'))->not->toContain($elsewhere->id)
        ->and($context->every(fn (ChatMessage $line): bool => $line->relationLoaded('user')))->toBeTrue();
});
