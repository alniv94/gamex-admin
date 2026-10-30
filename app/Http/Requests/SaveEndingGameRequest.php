<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveEndingGameRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can($this->routeIs('ending.games.store') ? 'ending.games.create' : 'ending.games.update') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'team_a' => ['required', 'integer', 'min:1'],
            'team_b' => ['required', 'integer', 'min:1', 'different:team_a'],
            'schedule' => ['required', 'date_format:Y-m-d\\TH:i'],
            'presets' => ['sometimes', 'array', 'max:100'],
            'presets.*.config_id' => ['required', 'integer', 'min:1'],
            'presets.*.bet_type_id' => ['required', 'integer', 'in:1,2'],
            'presets.*.quantity' => ['required', 'integer', 'between:0,1000'],
        ];
    }
}
