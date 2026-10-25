<?php

namespace App\Http\Requests\World;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only a loose bound on the raw input. SendChatMessage sanitizes the line and
 * refuses empty or overlong ones with a world refusal the client understands.
 */
final class ChatRequest extends FormRequest
{
    /** Generous enough for invisible characters the sanitizer will strip. */
    private const int RAW_MAX_LENGTH = 1000;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:'.self::RAW_MAX_LENGTH],
        ];
    }

    public function chatBody(): string
    {
        return $this->string('body')->toString();
    }
}
