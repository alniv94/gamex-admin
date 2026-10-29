<?php

namespace App\Models;

use Database\Factories\EndingLeagueFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $league
 * @property string|null $logo
 * @property string $is_local
 * @property string $is_active
 */
class EndingLeague extends EndingRecord
{
    /** @use HasFactory<EndingLeagueFactory> */
    use HasFactory;

    protected $fillable = ['league', 'logo', 'is_local', 'is_active', 'created_at'];

    /** @return HasMany<EndingTeam, $this> */
    public function teams(): HasMany
    {
        return $this->hasMany(EndingTeam::class, 'league_id');
    }

    /** @param Builder<EndingLeague> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', 'Active');
    }
}
