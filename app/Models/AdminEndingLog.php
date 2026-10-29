<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $game_id
 * @property int|null $user_id
 * @property string $action
 * @property string $subject_type
 * @property int $subject_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $reason
 */
class AdminEndingLog extends EndingRecord
{
    protected $fillable = ['game_id', 'user_id', 'action', 'subject_type', 'subject_id', 'before', 'after', 'reason', 'ip_address', 'created_at'];

    protected $hidden = ['ip_address'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [...parent::casts(), 'before' => 'array', 'after' => 'array'];
    }

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
