<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendPlayerSmsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('SMS') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'message' => ['required', 'string', 'max:160'],
            'blast' => ['required', 'boolean'],
            'player_ids' => ['required_if:blast,false', 'array', 'max:5000'],
            'player_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
