<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property string $amount
 * @property int $card_quantity
 * @property string $status
 */
class EndingPerQuarterBetAmountConfig extends EndingRecord
{
    protected $table = 'ending_per_quarter_bet_amount_config';

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [...parent::casts(), 'amount' => 'decimal:2'];
    }

    /** @param Builder<EndingPerQuarterBetAmountConfig> $query */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->where('status', 'Active')->where('card_quantity', '>', 0);
    }
}
