<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $player_id
 * @property int|null $legacy_player_id
 * @property int|null $legacy_player_mapping_id
 * @property int $card_id
 * @property int $slot_id
 * @property int $quarter_id
 * @property int $game_type_id
 * @property string|null $name
 * @property string|null $result
 * @property string $refunded
 */
class EndingBet extends EndingRecord
{
    protected $fillable = ['result'];

    protected $hidden = ['legacy_checksum', 'legacy_dates', 'geo_lat', 'geo_long', 'ip_address'];

    /** @return BelongsTo<EndingCard, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(EndingCard::class, 'card_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<EndingSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(EndingSlot::class, 'slot_id');
    }

    /** @return HasMany<EndingGameWinner, $this> */
    public function winners(): HasMany
    {
        return $this->hasMany(EndingGameWinner::class, 'bet_id');
    }

    /** @param Builder<EndingBet> $query */
    #[Scope]
    protected function forGame(Builder $query, int $gameId): void
    {
        $query->whereHas('card.betAmount', fn (Builder $amounts) => $amounts->where('game_id', $gameId));
    }
}
