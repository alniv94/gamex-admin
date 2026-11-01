<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveOtsoPanaloGameRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can($this->route('game') ? 'Update Otso Panalo' : 'Create Otso Panalo') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ending_game_id' => ['required', 'integer', 'exists:ending_games,id'],
            'betting_deadline' => [$this->route('game') ? 'required' : 'nullable', 'date'],
        ];
    }
}
