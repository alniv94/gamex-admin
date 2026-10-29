<?php

namespace App\Models;

use Database\Factories\EndingTeamFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $league_id
 * @property string $team_name
 * @property string|null $team_alias
 * @property string|null $logo
 * @property string $is_active
 */
class EndingTeam extends EndingRecord
{
    /** @use HasFactory<EndingTeamFactory> */
    use HasFactory;

    protected $fillable = ['league_id', 'team_name', 'team_alias', 'logo', 'is_active', 'created_at'];

    /** @return BelongsTo<EndingLeague, $this> */
    public function league(): BelongsTo
    {
        return $this->belongsTo(EndingLeague::class, 'league_id');
    }

    /** @param Builder<EndingTeam> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', 'Active');
    }
}
