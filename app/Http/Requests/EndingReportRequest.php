<?php

namespace App\Http\Requests;

use App\Services\Ending\EndingReports;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EndingReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(EndingReports::DEFINITIONS[$this->route('report')][1]) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $report = $this->route('report');

        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
            'league_id' => ['nullable', 'integer', 'min:1'],
            'team_id' => ['nullable', 'integer', 'min:1'],
            'game_id' => ['nullable', 'integer', 'min:1'],
            'card_id' => ['nullable', 'integer', 'min:1'],
            'slot_id' => ['nullable', 'integer', 'min:1'],
            'player_id' => ['nullable', 'integer', 'min:1'],
            'bet_type_id' => ['nullable', 'integer', Rule::in([1, 2])],
            'status' => ['required', Rule::in(['All', 'Not Started', 'Ongoing', 'Finished'])],
            'cashier_status' => ['required', Rule::in(['All', 'Pending', 'Verified', 'Approved', 'Disapproved', 'Rejected'])],
            'filter_by' => ['required', Rule::in(['scheduled', 'bet'])],
            'period' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'sort' => ['required', Rule::in(array_unique(['id', ...array_column(EndingReports::columns($report), 'key')]))],
            'direction' => ['required', Rule::in(['asc', 'desc'])],
            'per_page' => ['required', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['required', 'integer', 'min:1', 'max:1000000'],
            'scope' => ['required', Rule::in(['all', 'page'])],
            'format' => ['required', Rule::in(['csv', 'pdf'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $report = $this->route('report');
        $today = now('Asia/Manila')->toDateString();
        $defaults = ['date_from' => $today, 'date_to' => $this->input('date_from', $today), 'search' => '',
            'status' => 'All', 'cashier_status' => 'Approved', 'filter_by' => in_array($report, ['bets', 'transactional', 'cash-in', 'cash-out']) ? 'bet' : 'scheduled',
            'period' => 'daily', 'sort' => EndingReports::defaultSort($report), 'direction' => 'desc',
            'per_page' => 25, 'page' => 1, 'scope' => 'all', 'format' => 'csv'];
        $this->merge(array_replace($defaults, $this->all()));
    }
}
