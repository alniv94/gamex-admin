<?php

namespace App\Http\Requests;

use App\Services\Auth\AdminPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAdminRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(AdminPermissions::MANAGE_USERS) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => is_string($this->name) ? trim($this->name) : $this->name]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('admin_roles')->where('guard_name', 'web')->ignore($this->route('role'))],
            'permissions' => ['present', 'array', 'max:500'],
            'permissions.*' => ['string', 'distinct', Rule::exists('admin_acl_permissions', 'name')->where('guard_name', 'web')],
            'version' => [$this->route('role') ? 'required' : 'nullable', 'integer', 'min:0'],
        ];
    }
}
