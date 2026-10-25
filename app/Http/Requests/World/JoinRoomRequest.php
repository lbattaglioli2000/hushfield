<?php

namespace App\Http\Requests\World;

use App\Models\RoomInstance;
use Illuminate\Foundation\Http\FormRequest;

final class JoinRoomRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // An unknown or unsuitable instance is ignored rather than refused; JoinRoom falls back to its normal rule.
            'instance_id' => ['nullable', 'integer'],
        ];
    }

    public function preferredInstance(): ?RoomInstance
    {
        return $this->filled('instance_id') ? RoomInstance::query()->find($this->integer('instance_id')) : null;
    }
}
