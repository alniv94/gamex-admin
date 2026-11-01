<?php

namespace App\Http\Requests;

use App\Services\OtsoPanalo\OtsoPanaloWorkflow;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveOtsoPanaloResultRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('put') ? 'Otso Panalo Update Result' : 'Otso Panalo Create Result') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [];
        if ($this->isMethod('put')) {
            $rules['version'] = ['required', 'integer', 'min:1'];
        }
        foreach (['first', 'second', 'third', 'fourth'] as $quarter) {
            $rules[$quarter.'_quarter_result'] = ['nullable', 'regex:/^[0-9]-[0-9]$/D'];
        }

        return $rules;
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (collect($this->only(array_map(fn ($quarter) => $quarter.'_quarter_result', OtsoPanaloWorkflow::QUARTERS)))->filter(fn ($value) => $value !== null && $value !== '')->isEmpty()) {
                $validator->errors()->add('first_quarter_result', 'At least one quarter result is required.');
            }
        }];
    }
}
