<?php

namespace App\Http\Requests\World;

use Illuminate\Foundation\Http\FormRequest;

final class MoveRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'x' => ['required', 'integer', 'min:0', 'max:1000'],
            'y' => ['required', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
