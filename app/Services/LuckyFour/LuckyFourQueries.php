<?php

namespace App\Services\LuckyFour;

use App\Models\EndingGame;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

class LuckyFourQueries
{
    public function ready(): bool
    {
        return DB::table('admin_lucky_four_imports')->where('source', config('ending.legacy_source'))->exists();
    }

    /** @return EloquentBuilder<EndingGame> */
    public function games(): EloquentBuilder
    {
        return EndingGame::query()->where('is_lucky_four', 'Yes')->with(['teamA.league', 'teamB', 'gameStatus']);
    }

    public function bets(?int $gameId = null): Builder
    {
        return DB::table('lucky_four_bets as l')->join('ending_bets as b', 'b.id', '=', 'l.ending_bet_id')
            ->leftJoin('ending_cards as c', 'c.id', '=', 'b.card_id')
            ->leftJoin('players as p', 'p.id', '=', 'b.player_id')
            ->leftJoin('lucky_four_winners as w', 'w.ending_bet_id', '=', 'l.ending_bet_id')
            ->when($gameId !== null, fn (Builder $q) => $q->where('l.game_id', $gameId))
            ->select(['l.id', 'l.ending_bet_id', 'l.game_id', 'l.combination', 'l.amount', 'l.is_added', 'b.player_id', 'p.name as player_name',
                'b.created_at', 'b.refunded', 'b.card_id', 'b.game_type_id', 'c.refunded as card_refunded', 'w.id as winner_id', 'w.winner_status', 'w.prize_amount', 'w.prize_released']);
    }

    public function result(int $gameId): ?string
    {
        $rows = DB::table('ending_game_results')->where('game_id', $gameId)->orderBy('quarter_id')->get(['quarter_id', 'lucky_four_result']);
        if ($rows->count() !== 4 || $rows->pluck('quarter_id')->map(fn ($quarter) => (int) $quarter)->all() !== [1, 2, 3, 4]) {
            return null;
        }
        $result = '';
        foreach ($rows as $row) {
            if ($row->lucky_four_result === null || ! preg_match('/^[0-9]$/D', (string) $row->lucky_four_result)) {
                return null;
            }
            $result .= $row->lucky_four_result;
        }

        return $result;
    }

    public function winners(int $gameId): Builder
    {
        $result = $this->result($gameId);
        $query = $this->bets($gameId);
        if ($result === null) {
            return $query->whereNotNull('w.id')->addSelect(DB::raw('NULL AS tier'), DB::raw('NULL AS calculated_prize'));
        }
        $count = DB::table('lucky_four_bets')->where('game_id', $gameId)->where('combination', $result)->count();
        $prizes = DB::table('lucky_four_prizes')->where('game_id', $gameId)->first();
        $jackpot = $prizes?->jackpot_prize;
        $share = $jackpot !== null && $count > 0 ? LuckyFourWorkflow::decimal(intdiv(LuckyFourWorkflow::cents((string) $jackpot), $count)) : null;
        $first = substr($result, 0, 3).'%';
        $last = '%'.substr($result, 1);

        return $query->where(fn (Builder $q) => $q->where('l.combination', $result)->orWhere('l.combination', 'like', $first)
            ->orWhere('l.combination', 'like', $last)->orWhereNotNull('w.id'))
            ->selectRaw('CASE WHEN l.combination = ? THEN ? WHEN l.combination LIKE ? OR l.combination LIKE ? THEN ? ELSE NULL END AS tier', [$result, 'Jackpot', $first, $last, 'Three digits'])
            ->selectRaw('CASE WHEN l.combination = ? THEN ? WHEN l.combination LIKE ? OR l.combination LIKE ? THEN ? ELSE NULL END AS calculated_prize', [$result, $share, $first, $last, $prizes?->three_digits_prize]);
    }

    /** @return array<string, mixed> */
    public static function publicData(stdClass $row): array
    {
        $data = (array) $row;
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, 'legacy_') || $key === 'managed_at') {
                unset($data[$key]);
            } elseif (str_ends_with($key, '_at') && $data[$key] !== null) {
                $data[$key] = CarbonImmutable::parse($data[$key], 'UTC')->toIso8601String();
            }
        }

        return $data;
    }
}
