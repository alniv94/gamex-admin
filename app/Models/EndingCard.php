<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $bet_amount_id
 * @property string $betting
 * @property string $generated_file
 * @property string|null $prize_released
 * @property string $refunded
 * @property int $bets_count
 */
class EndingCard extends EndingRecord
{
    protected $fillable = ['bet_amount_id', 'betting', 'created_at'];

    /** @return BelongsTo<EndingBetAmount, $this> */
    public function betAmount(): BelongsTo
    {
        return $this->belongsTo(EndingBetAmount::class, 'bet_amount_id');
    }

    /** @return HasMany<EndingBet, $this> */
    public function bets(): HasMany
    {
        return $this->hasMany(EndingBet::class, 'card_id');
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id, 'bet_amount_id' => $this->bet_amount_id, 'betting' => $this->betting,
            'generated_file' => $this->generated_file, 'refunded' => $this->refunded,
            'amount' => $this->betAmount?->amount, 'bet_type_id' => $this->betAmount?->bet_type_id,
            'bets' => $this->bets_count,
        ];
    }
}
