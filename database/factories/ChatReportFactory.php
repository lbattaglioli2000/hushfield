<?php

namespace Database\Factories;

use App\Domain\Moderation\ReportReason;
use App\Domain\Moderation\ReportStatus;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatReport>
 */
class ChatReportFactory extends Factory
{
    public function definition(): array
    {
        $message = ChatMessage::factory()->create();

        return [
            'reporter_id' => User::factory(),
            'reported_user_id' => $message->user_id,
            'chat_message_id' => $message->id,
            'room_instance_id' => $message->room_instance_id,
            'reason' => ReportReason::Harassment,
            'status' => ReportStatus::Open,
        ];
    }
}
