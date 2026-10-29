<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $bet_amount_id
 * @property int $quarter_id
 * @property string $is_cash
 * @property string|null $cash_prize
 * @property string|null $other_prize
 */
class EndingBetAmountPrize extends EndingRecord
{
    protected $fillable = ['bet_amount_id', 'quarter_id', 'is_cash', 'cash_prize', 'other_prize'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [...parent::casts(), 'cash_prize' => 'decimal:2'];
    }

    /** @return BelongsTo<EndingBetAmount, $this> */
    public function betAmount(): BelongsTo
    {
        return $this->belongsTo(EndingBetAmount::class, 'bet_amount_id');
    }
}
