<?php

namespace App\Services\OtsoPanalo;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

class OtsoPanaloQueries
{
    public function games(): Builder
    {
        return DB::table('otso_panalo_games as g')
            ->join('ending_games as e', 'e.id', '=', 'g.ending_game_id')
            ->leftJoin('ending_teams as a', 'a.id', '=', 'e.team_a')
            ->leftJoin('ending_teams as b', 'b.id', '=', 'e.team_b')
            ->leftJoin('ending_leagues as l', 'l.id', '=', 'a.league_id')
            ->leftJoin('otso_panalo_statuses as s', 's.id', '=', 'g.game_status_id')
            ->leftJoin('otso_panalo_game_configs as c', 'c.id', '=', 'g.game_config_id')
            ->select('g.*', 'e.schedule', 'l.league', 'a.team_name as team_a_name', 'a.team_alias as team_a_alias', 'a.logo as team_a_logo',
                'b.team_name as team_b_name', 'b.team_alias as team_b_alias', 'b.logo as team_b_logo', 's.name as game_status', 'c.name as game_config_name', 'c.logo as logo');
    }

    /** @return array<int, array<string, mixed>> */
    public function available(?int $current = null): array
    {
        return DB::table('ending_games as e')->join('ending_teams as a', 'a.id', '=', 'e.team_a')->join('ending_teams as b', 'b.id', '=', 'e.team_b')
            ->join('ending_leagues as l', 'l.id', '=', 'a.league_id')->where('l.league', 'NBA')->whereNull('e.game_status_id')
            ->where('e.schedule', '>', now()->addMinutes(30))
            ->whereNotExists(function (Builder $query) use ($current): void {
                $query->selectRaw('1')->from('otso_panalo_games as g')->whereColumn('g.ending_game_id', 'e.id')->when($current, fn ($q) => $q->where('g.id', '<>', $current));
            })
            ->orderBy('e.schedule')->orderBy('e.id')->limit(200)
            ->get(['e.id', 'e.schedule', 'a.team_name as team_a_name', 'b.team_name as team_b_name', 'l.league'])
            ->map(fn ($row) => self::publicData($row))->all();
    }

    public function bets(): Builder
    {
        $query = DB::table('otso_panalo_bets as b')->leftJoin('players as p', 'p.id', '=', 'b.player_id')
            ->join('otso_panalo_games as g', 'g.id', '=', 'b.game_id')->join('ending_games as e', 'e.id', '=', 'g.ending_game_id')
            ->leftJoin('ending_teams as a', 'a.id', '=', 'e.team_a')->leftJoin('ending_teams as z', 'z.id', '=', 'e.team_b')
            ->leftJoin('ending_leagues as l', 'l.id', '=', 'a.league_id')
            ->leftJoin('otso_panalo_bet_types as t', 't.id', '=', 'b.bet_type_id')
            ->leftJoin('otso_panalo_winners as w', 'w.bet_id', '=', 'b.id')
            ->leftJoin('otso_panalo_game_results as r', 'r.game_id', '=', 'g.id')
            ->select('b.*', 'p.username', 'p.name as player_name', 'e.schedule', 'l.league', 'a.team_name as team_a_name', 'z.team_name as team_b_name',
                'a.team_alias as team_a_alias', 'z.team_alias as team_b_alias', 't.bet_type', 'w.winner_status', 'w.prize_amount', 'w.winner_type_id', 'w.prize_released');
        foreach (OtsoPanaloWorkflow::QUARTERS as $index => $quarter) {
            $alias = 'q'.($index + 1);
            $query->leftJoin('otso_panalo_bet_quarter_results as '.$alias, fn ($join) => $join->on($alias.'.bet_id', '=', 'b.id')->where($alias.'.quarter_number', $index + 1));
            $query->selectRaw("COALESCE({$alias}.quarter_status, CASE WHEN r.status = 'Approved' THEN CASE WHEN NULLIF(TRIM(b.{$quarter}_quarter_combination), '') = NULLIF(TRIM(r.{$quarter}_quarter_result), '') THEN 'Win' ELSE 'Lose' END ELSE NULL END) AS {$quarter}_quarter_status");
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    public function filteredBets(array $filters): Builder
    {
        $query = $this->bets();
        if (! empty($filters['game_id'])) {
            $query->where('b.game_id', $filters['game_id']);
        }
        $this->dates($query, $filters, ($filters['filter_by'] ?? 'Bet Date') === 'Game Schedule' ? 'e.schedule' : 'b.created_at');
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                foreach (['b.id', 'p.username', 'p.name', 'b.name', 't.bet_type', 'l.league', 'a.team_name', 'a.team_alias', 'z.team_name', 'z.team_alias', ...array_map(fn ($quarter) => 'b.'.$quarter.'_quarter_combination', OtsoPanaloWorkflow::QUARTERS)] as $column) {
                    $q->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        return $query->orderByDesc('b.created_at')->orderByDesc('b.id');
    }

    public function winners(int $gameId): Builder
    {
        return DB::table('otso_panalo_winners as w')->join('otso_panalo_bets as b', 'b.id', '=', 'w.bet_id')
            ->leftJoin('players as p', 'p.id', '=', 'b.player_id')->leftJoin('otso_panalo_winner_types as t', 't.id', '=', 'w.winner_type_id')
            ->where('b.game_id', $gameId)->select('w.*', 'b.player_id', 'p.username', 'p.name as player_name', 'b.name as ticket_name', 't.winner_type',
                ...array_map(fn ($quarter) => 'b.'.$quarter.'_quarter_combination', OtsoPanaloWorkflow::QUARTERS));
    }

    /** @param array<string, mixed> $filters */
    public function dates(Builder $query, array $filters, string $column): void
    {
        if (! empty($filters['date_from'])) {
            $query->where($column, '>=', CarbonImmutable::parse($filters['date_from'], 'Asia/Manila')->startOfDay()->utc());
            $query->where($column, '<', CarbonImmutable::parse($filters['date_to'] ?? $filters['date_from'], 'Asia/Manila')->addDay()->startOfDay()->utc());
        } elseif (! empty($filters['date_to'])) {
            $query->where($column, '<', CarbonImmutable::parse($filters['date_to'], 'Asia/Manila')->addDay()->startOfDay()->utc());
        }
    }

    /** @param stdClass|array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function publicData(stdClass|array $row): array
    {
        $data = (array) $row;
        unset($data['legacy_payload'], $data['legacy_checksum'], $data['legacy_dates'], $data['managed_at']);
        foreach ($data as $key => $value) {
            if ($value !== null && (str_ends_with($key, '_at') || in_array($key, ['schedule', 'betting_deadline'], true))) {
                $data[$key] = CarbonImmutable::parse($value, 'UTC')->toIso8601String();
            }
        }

        return $data;
    }
}
