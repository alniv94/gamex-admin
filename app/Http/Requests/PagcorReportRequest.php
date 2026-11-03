<?php

namespace App\Http\Requests;

use App\Services\Pagcor\PagcorReports;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PagcorReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(PagcorReports::DEFINITIONS[$this->route('report')][1]) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'submitted' => ['required', 'boolean'],
            'date_from' => ['required_if:submitted,1', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'league_id' => ['nullable', 'integer', 'min:1'],
            'team_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['All', 'Not Started', 'Ongoing', 'Finished'])],
            'period' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'gaming_venue' => [Rule::requiredIf($this->boolean('submitted') && $this->route('report') === 'transactional'), 'nullable', Rule::in(['lascasas_bagac_bataan'])],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['required', Rule::in(array_column(PagcorReports::columns($this->route('report')), 'key'))],
            'direction' => ['required', Rule::in(['asc', 'desc'])],
            'per_page' => ['required', 'integer', Rule::in([5, 10, 25, 50, 100])],
            'page' => ['required', 'integer', 'between:1,1000000'],
            'scope' => ['required', Rule::in(['all', 'page'])],
            'format' => ['required', Rule::in(['csv', 'pdf'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_replace(['submitted' => $this->routeIs('pagcor.reports.export') ? 1 : 0,
            'date_from' => null, 'date_to' => null, 'status' => 'All', 'period' => 'daily', 'search' => '',
            'sort' => PagcorReports::defaultSort($this->route('report')), 'direction' => 'asc',
            'per_page' => $this->route('report') === 'transactional' ? 5 : 25, 'page' => 1, 'scope' => 'all', 'format' => 'csv'], $this->all()));
        if ($this->filled('date_from') && ! $this->filled('date_to')) {
            $this->merge(['date_to' => $this->input('date_from')]);
        }
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['date_from', 'date_to']) || ! $this->filled('date_from') || ! $this->filled('date_to')) {
                return;
            }
            if (CarbonImmutable::parse($this->input('date_from'))->diffInDays(CarbonImmutable::parse($this->input('date_to'))) > 9999) {
                $validator->errors()->add('date_to', 'Choose a date range of 10,000 days or fewer.');
            }
        }];
    }
}
