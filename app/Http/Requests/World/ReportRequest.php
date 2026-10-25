<?php

namespace App\Http\Requests\World;

use App\Domain\Moderation\ReportReason;
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
}
