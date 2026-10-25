<?php

namespace App\Actions\Moderation;

use App\Domain\Moderation\ReportReason;
use App\Domain\Moderation\ReportStatus;
use App\Exceptions\WorldActionRefused;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Files a player report. When the reporter clicked an avatar rather than a
 * bubble, the reported player's latest line in that instance is attached so
 * moderators still get context.
 */
final readonly class ReportMessage
{
    public function __invoke(User $reporter, User $reported, ReportReason $reason, ?ChatMessage $message = null, ?RoomInstance $instance = null): ChatReport
    {
        if ($reporter->is($reported)) {
            throw WorldActionRefused::reportSelf();
        }

        $instanceId = $message->room_instance_id ?? $instance?->id;

        $message ??= $instanceId === null ? null : ChatMessage::query()
            ->where('room_instance_id', $instanceId)
            ->where('user_id', $reported->id)
            ->latest('id')
            ->first();

        return ChatReport::query()->create([
            'reporter_id' => $reporter->id,
            'reported_user_id' => $reported->id,
            'chat_message_id' => $message?->id,
            'room_instance_id' => $instanceId,
            'reason' => $reason,
            'status' => ReportStatus::Open,
        ]);
    }
}
