<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EndingGameFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property int $team_a
 * @property int $team_b
 * @property CarbonImmutable|null $schedule
 * @property string $betting
 * @property int|null $game_status_id
 * @property string|null $is_max
 * @property string|null $is_lucky_four
 * @property string|null $is_power_ball
 * @property string|null $prize_released
 * @property CarbonImmutable|null $enabled_betting_at
 * @property CarbonImmutable|null $disabled_betting_at
 */
class EndingGame extends EndingRecord
{
    /** @use HasFactory<EndingGameFactory> */
    use HasFactory;

    protected $fillable = ['team_a', 'team_b', 'schedule', 'betting', 'game_status_id', 'enabled_betting_at', 'disabled_betting_at', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [...parent::casts(), 'schedule' => 'immutable_datetime', 'enabled_betting_at' => 'immutable_datetime', 'disabled_betting_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<EndingTeam, $this> */
    public function teamA(): BelongsTo
    {
        return $this->belongsTo(EndingTeam::class, 'team_a');
    }

    /** @return BelongsTo<EndingTeam, $this> */
    public function teamB(): BelongsTo
    {
        return $this->belongsTo(EndingTeam::class, 'team_b');
    }

    /** @return BelongsTo<EndingGameStatus, $this> */
    public function gameStatus(): BelongsTo
    {
        return $this->belongsTo(EndingGameStatus::class, 'game_status_id');
    }

    /** @return HasMany<EndingBetAmount, $this> */
    public function betAmounts(): HasMany
    {
        return $this->hasMany(EndingBetAmount::class, 'game_id');
    }

    /** @return HasManyThrough<EndingCard, EndingBetAmount, $this> */
    public function cards(): HasManyThrough
    {
        return $this->hasManyThrough(EndingCard::class, EndingBetAmount::class, 'game_id', 'bet_amount_id');
    }

    /** @return HasMany<EndingGameResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(EndingGameResult::class, 'game_id');
    }

    /** @return HasMany<AdminEndingLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(AdminEndingLog::class, 'game_id');
    }

    /** @return HasMany<AdminEndingLegacyLog, $this> */
    public function legacyLogs(): HasMany
    {
        return $this->hasMany(AdminEndingLegacyLog::class, 'game_id');
    }

    /** @param Builder<EndingGame> $query */
    #[Scope]
    protected function native(Builder $query): void
    {
        $query->whereNull('legacy_checksum');
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id, 'team_a' => $this->team_a, 'team_b' => $this->team_b,
            'team_a_name' => $this->teamA?->team_name, 'team_b_name' => $this->teamB?->team_name,
            'team_a_logo' => $this->teamA?->logo, 'team_b_logo' => $this->teamB?->logo,
            'league_id' => $this->teamA?->league_id, 'league' => $this->teamA?->league?->league,
            'schedule' => $this->schedule?->toIso8601String(), 'betting' => $this->betting,
            'game_status_id' => $this->game_status_id, 'status' => $this->gameStatus->status ?? 'Scheduled',
            'is_max' => $this->is_max, 'is_lucky_four' => $this->is_lucky_four, 'is_power_ball' => $this->is_power_ball,
            'created_at' => $this->created_at?->toIso8601String(), 'enabled_betting_at' => $this->enabled_betting_at?->toIso8601String(),
        ];
    }
}
