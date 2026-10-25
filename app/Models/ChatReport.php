<?php

namespace App\Models;

use App\Domain\Moderation\ReportReason;
use App\Domain\Moderation\ReportResolution;
use App\Domain\Moderation\ReportStatus;
use Database\Factories\ChatReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $reporter_id
 * @property int $reported_user_id
 * @property int|null $chat_message_id
 * @property int|null $room_instance_id
 * @property ReportReason $reason
 * @property ReportStatus $status
 * @property ReportResolution|null $resolution
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property-read User $reporter
 * @property-read User $reportedUser
 * @property-read ChatMessage|null $chatMessage
 * @property-read RoomInstance|null $roomInstance
 * @property-read User|null $resolver
 */
#[Fillable(['reporter_id', 'reported_user_id', 'chat_message_id', 'room_instance_id', 'reason', 'status', 'resolution', 'resolved_by', 'resolved_at'])]
class ChatReport extends Model
{
    /** @use HasFactory<ChatReportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'resolution' => ReportResolution::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reportedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }

    /** @return BelongsTo<ChatMessage, $this> */
    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class);
    }

    /** @return BelongsTo<RoomInstance, $this> */
    public function roomInstance(): BelongsTo
    {
        return $this->belongsTo(RoomInstance::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
