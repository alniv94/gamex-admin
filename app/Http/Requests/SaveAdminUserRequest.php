<?php

namespace App\Http\Requests;

use App\Services\Auth\AdminPermissions;
use App\Services\Auth\MobileNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(AdminPermissions::MANAGE_USERS) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'mobile_number' => is_string($this->mobile_number) ? MobileNumber::normalize($this->mobile_number) : null,
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'mobile_number' => ['required', 'string', 'regex:/^\+639[0-9]{9}$/', Rule::unique('users')->ignore($this->route('user'))],
            'email' => ['nullable', 'email:rfc', 'max:150', Rule::unique('users')->ignore($this->route('user'))],
            'roles' => ['present', 'array', 'max:100'],
            'roles.*' => ['integer', 'distinct', Rule::exists('admin_roles', 'id')->where('guard_name', 'web')],
            'permissions' => ['present', 'array', 'max:500'],
            'permissions.*' => ['string', 'distinct', Rule::exists('admin_acl_permissions', 'name')->where('guard_name', 'web')],
            'version' => [$this->route('user') ? 'required' : 'nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['mobile_number.required' => 'Enter a valid Philippine mobile number.'];
    }
}
