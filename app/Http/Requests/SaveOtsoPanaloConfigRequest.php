<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveOtsoPanaloConfigRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('Otso Panalo Update Game Types') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255', Rule::unique('otso_panalo_game_configs')->ignore($this->route('config'))],
            'status' => ['required', 'in:Active,Inactive'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'pool_percentage' => ['required', 'numeric', 'decimal:0,2', 'between:0,100'],
        ];
        foreach (['bet_amount', 'jackpot_prize', 'second_prize', 'third_prize', 'fourth_prize'] as $column) {
            $rules[$column] = ['required', 'numeric', 'decimal:0,2', $column === 'bet_amount' ? 'gt:0' : 'min:0', 'max:99999999.99'];
        }

        return $rules;
    }
}
