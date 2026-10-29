<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Ending\EndingAccess;
use Illuminate\Foundation\Http\FormRequest;

class CreateEndingCardsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(EndingAccess $access): bool
    {
        $user = $this->user();

        return $user instanceof User && $access->allows($user, 12);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'between:1,1000'], 'bet_amount_id' => ['nullable', 'integer', 'min:1'],
            'bet_type_id' => ['required_without:bet_amount_id', 'integer', 'in:1,2'],
            'amount' => ['required_without:bet_amount_id', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2'],
            'prizes' => ['required_without:bet_amount_id', 'array', 'size:4'],
            'prizes.*.quarter_id' => ['required', 'integer', 'between:1,4', 'distinct'],
            'prizes.*.is_cash' => ['required', 'in:Cash,Not Cash'],
            'prizes.*.cash_prize' => ['nullable', 'required_if:prizes.*.is_cash,Cash', 'numeric', 'between:0,99999999.99', 'decimal:0,2'],
            'prizes.*.other_prize' => ['nullable', 'required_if:prizes.*.is_cash,Not Cash', 'string', 'max:255'],
        ];
    }
}
