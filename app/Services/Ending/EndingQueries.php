<?php

namespace App\Services\Ending;

use App\Models\EndingGame;
use App\Models\EndingGameResult;
use App\Models\EndingLeague;
use App\Models\EndingPerQuarterBetAmountConfig;
use App\Models\EndingTeam;
use App\Models\EndingTraditionalBetAmountConfig;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

class EndingQueries
{
    /** @return EloquentBuilder<EndingGame> */
    public function games(): EloquentBuilder
    {
        return EndingGame::query()->with(['teamA.league', 'teamB', 'gameStatus']);
    }

    public function winners(int $game): Builder
    {
        // The legacy type names are counterintuitive: type 2 applies a slot to every
        // quarter; type 1 uses the quarter attached to the bet. Preserve this rule.
        return EndingGameResult::query()->toBase()->from('ending_game_results as r')->join('ending_bet_amounts as a', 'r.game_id', '=', 'a.game_id')
            ->join('ending_cards as c', 'a.id', '=', 'c.bet_amount_id')->join('ending_bets as b', 'c.id', '=', 'b.card_id')
            ->leftJoin('ending_game_winners as w', function ($join): void {
                $join->on('b.id', '=', 'w.bet_id')->on('r.quarter_id', '=', 'w.quarter_id');
            })
            ->leftJoin('ending_bet_amount_prizes as p', function ($join): void {
                $join->on('a.id', '=', 'p.bet_amount_id')->on('r.quarter_id', '=', 'p.quarter_id');
            })
            ->leftJoin('players as player', 'b.player_id', '=', 'player.id')->leftJoin('ending_slots as s', 'r.slot_id', '=', 's.id')
            ->where('r.game_id', $game)->where(function (Builder $q): void {
                $q->whereNotNull('w.id')->orWhere(function (Builder $q): void {
                    $q->whereColumn('b.slot_id', 'r.slot_id')->where('b.refunded', 'No')->where('c.refunded', 'No')->whereNotNull('p.id')
                        ->where(function (Builder $q): void {
                            $q->where('a.bet_type_id', 2)->orWhereColumn('b.quarter_id', 'r.quarter_id');
                        });
                });
            })
            ->select('b.id as bet_id', 'b.player_id', 'b.legacy_player_id', 'b.card_id', 'b.name as ticket_name', 'player.name as player_name', 'r.quarter_id', 's.slot as combination', 'p.is_cash', 'p.cash_prize', 'p.other_prize', 'w.id as winner_id', 'w.winner_status_id', 'w.prize_released', 'w.approved_at')
            ->selectRaw("CASE WHEN b.slot_id = r.slot_id AND (a.bet_type_id = 2 OR b.quarter_id = r.quarter_id) AND b.refunded = 'No' AND c.refunded = 'No' AND p.id IS NOT NULL THEN 1 ELSE 0 END as eligible")
            ->selectRaw("CASE WHEN w.winner_status_id = 2 THEN 'Approved' WHEN w.winner_status_id = 1 THEN 'Verified' ELSE 'For Verification' END as status");
    }

    /** @return array<string, mixed> */
    public function lookups(): array
    {
        $presets = [];
        foreach ([1 => EndingTraditionalBetAmountConfig::class, 2 => EndingPerQuarterBetAmountConfig::class] as $type => $table) {
            foreach ($table::available()->orderBy('amount')->get() as $preset) {
                $presets[] = ['config_id' => $preset->id, 'bet_type_id' => $type, 'amount' => $preset->amount, 'quantity' => $preset->card_quantity];
            }
        }

        return [
            'leagues' => EndingLeague::active()->orderBy('league')->get(['id', 'league', 'logo']),
            'teams' => EndingTeam::active()->orderBy('team_name')->get(['id', 'league_id', 'team_name', 'logo']),
            'presets' => $presets,
        ];
    }
}
