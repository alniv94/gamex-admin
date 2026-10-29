<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $game_id
 * @property int $quarter_id
 * @property int $team_a_score
 * @property int $team_b_score
 * @property int $slot_id
 * @property int|null $lucky_four_result
 * @property CarbonImmutable|null $updated_at
 */
class EndingGameResult extends EndingRecord
{
    protected $fillable = ['game_id', 'quarter_id', 'team_a_score', 'team_b_score', 'slot_id', 'created_at', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [...parent::casts(), 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<EndingGame, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(EndingGame::class, 'game_id');
    }

    /** @return BelongsTo<EndingSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(EndingSlot::class, 'slot_id');
    }
}
