<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $game_id
 * @property int $user_id
 * @property string|null $action
 * @property int $reference_id
 * @property string|null $details
 * @property string|null $remarks
 * @property string|null $reject_reason
 */
class AdminEndingLegacyLog extends EndingRecord
{
    protected $guarded = ['*'];

    protected $hidden = ['legacy_checksum', 'legacy_dates', 'ip_address', 'player_id'];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<EndingGame, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(EndingGame::class, 'game_id');
    }
}
