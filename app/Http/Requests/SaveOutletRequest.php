<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveOutletRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('Outlets') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'outlet_name' => ['required', 'string', 'min:2', 'max:255',
                Rule::unique('admin_outlets', 'outlet_name')->where('barangay_id', $this->input('barangay_id'))
                    ->ignore($this->route('outlet') ? (int) $this->route('outlet') : null)],
            'city_id' => ['required', 'integer', Rule::exists('cities', 'id')],
            'barangay_id' => ['required', 'integer', Rule::exists('barangays', 'id')->where('city_id', $this->input('city_id'))],
            'address' => ['required', 'string', 'min:5', 'max:200'],
            'is_active' => ['required', Rule::in(['Active', 'Inactive'])],
            'version' => [$this->route('outlet') ? 'required' : 'exclude', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'outlet_name.unique' => 'An outlet with this name already exists in the selected barangay.',
            'barangay_id.exists' => 'Choose a barangay in the selected city or municipality.',
        ];
    }
}
