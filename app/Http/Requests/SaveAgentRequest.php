<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SaveAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('Ending Agents') && ($this->route('agent') !== null || $this->user()->can('Ending Agents Create'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('agent');
        $usernameChanged = $id === null || $this->input('username') !== DB::table('admin_ending_agents')->where('id', $id)->value('username');

        return [
            'outlet_id' => ['required', 'integer', Rule::exists('admin_outlets', 'id')],
            'first_name' => ['required', 'string', 'min:2', 'max:30'],
            'middle_name' => ['nullable', 'string', 'max:30'],
            'last_name' => ['required', 'string', 'min:2', 'max:30'],
            'mobile_number' => ['required', 'regex:/^9[0-9]{9}$/D', Rule::unique('admin_ending_agent_info', 'mobile_number')->ignore($id, 'agent_id')],
            'email_address' => ['required', 'email', 'max:150', Rule::unique('admin_ending_agent_info', 'email_address')->ignore($id, 'agent_id')],
            'username' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_.-]+$/D', ...($usernameChanged ? [Rule::unique('admin_ending_agents', 'username')->ignore($id)] : [])],
            'password' => [$id ? 'prohibited' : 'required', 'string', 'min:8', 'max:255', 'confirmed'],
            'commission' => ['required', 'numeric', 'decimal:0,2', 'between:0,100'],
            'is_active' => ['required', Rule::in(['Active', 'Inactive'])],
            'type' => ['required', Rule::in(['Regular', 'Special'])],
            'sub_type' => ['nullable', Rule::in(['Streamer', 'Special', 'Employee Agent'])],
            'page_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
