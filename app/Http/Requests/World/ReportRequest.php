<?php

namespace App\Http\Requests\World;

use App\Domain\Moderation\ReportReason;
use App\Models\ChatMessage;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReportRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reported_user_id' => ['required', 'integer', 'exists:users,id'],
            'chat_message_id' => ['nullable', 'integer', Rule::exists('chat_messages', 'id')->where('user_id', $this->integer('reported_user_id'))],
            'room_instance_id' => ['nullable', 'integer', 'exists:room_instances,id'],
            'reason' => ['required', Rule::enum(ReportReason::class)],
        ];
    }

    public function reportedUser(): User
    {
        return User::query()->findOrFail($this->integer('reported_user_id'));
    }

    public function reason(): ReportReason
    {
        return ReportReason::from($this->string('reason')->toString());
    }

    public function chatMessage(): ?ChatMessage
    {
        return $this->filled('chat_message_id') ? ChatMessage::query()->find($this->integer('chat_message_id')) : null;
    }

    public function roomInstance(): ?RoomInstance
    {
        return $this->filled('room_instance_id') ? RoomInstance::query()->find($this->integer('room_instance_id')) : null;
    }
}
