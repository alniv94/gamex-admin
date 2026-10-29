<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $bet_id
 * @property int $quarter_id
 * @property int $winner_status_id
 * @property string $prize_released
 * @property int|null $verified_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 */
class EndingGameWinner extends EndingRecord
{
    protected $fillable = ['bet_id', 'quarter_id', 'winner_status_id', 'verified_by', 'approved_by', 'approved_at', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [...parent::casts(), 'approved_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<EndingBet, $this> */
    public function bet(): BelongsTo
    {
        return $this->belongsTo(EndingBet::class, 'bet_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @param Builder<EndingGameWinner> $query */
    #[Scope]
    protected function forGame(Builder $query, int $gameId): void
    {
        $query->whereHas('bet', fn (Builder $bets) => $bets->forGame($gameId));
    }
}
