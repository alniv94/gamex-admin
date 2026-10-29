<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $game_id
 * @property string|null $amount
 * @property int $bet_type_id
 * @property int $cards_count
 * @property int $disabled_cards_count
 * @property int $generated_cards_count
 * @property int $exceeded_cards_count
 */
class EndingBetAmount extends EndingRecord
{
    protected $fillable = ['game_id', 'amount', 'bet_type_id', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [...parent::casts(), 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<EndingGame, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(EndingGame::class, 'game_id');
    }

    /** @return HasMany<EndingCard, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(EndingCard::class, 'bet_amount_id');
    }

    /** @return HasMany<EndingBetAmountPrize, $this> */
    public function prizes(): HasMany
    {
        return $this->hasMany(EndingBetAmountPrize::class, 'bet_amount_id');
    }
}
