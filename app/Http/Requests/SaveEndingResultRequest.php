<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Ending\EndingAccess;
use Illuminate\Foundation\Http\FormRequest;

class SaveEndingResultRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(EndingAccess $access): bool
    {
        $user = $this->user();

        return $user instanceof User && $access->allows($user, 11);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'quarter_id' => ['required', 'integer', 'between:1,4'],
            'team_a_score' => ['required', 'integer', 'between:0,9999'],
            'team_b_score' => ['required', 'integer', 'between:0,9999'],
            'reason' => [$this->route('result') ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }
}
