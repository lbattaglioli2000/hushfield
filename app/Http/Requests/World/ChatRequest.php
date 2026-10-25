<?php

namespace App\Http\Requests\World;

use App\Domain\Chat\ChatSanitizer;
use Illuminate\Foundation\Http\FormRequest;

final class ChatRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => app(ChatSanitizer::class)->sanitize($this->input('body'))]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.(int) config('hushfield.chat.max_length')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['body.required' => (string) __('hushfield.chat_empty')];
    }
}
