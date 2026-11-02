<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInvestorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Investors') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('investor');

        return [
            'outlet_id' => ['required', 'integer', Rule::exists('admin_outlets', 'id')],
            'territory' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
            'username' => [$id ? 'exclude' : 'required', 'string', 'min:6', 'max:45', Rule::unique('admin_investors', 'username')],
            'first_name' => ['required', 'string', 'min:2', 'max:45'],
            'last_name' => ['required', 'string', 'min:2', 'max:45'],
            'mobile_number' => ['required', 'regex:/^9[0-9]{9}$/', Rule::unique('admin_investors', 'mobile_number')->ignore($id)],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:8', 'max:255'],
            'base_commission' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'commission' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'sub_commission' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'wallet' => [$id ? 'exclude' : 'required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'category' => ['required', Rule::in(['Regular', 'Special'])],
            'link_type' => ['required', Rule::in(['Old', 'New'])],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
        ];
    }
}
