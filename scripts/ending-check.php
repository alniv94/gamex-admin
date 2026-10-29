<?php

use App\Services\Ending\EndingQueries;
use App\Services\Legacy\EndingImport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Read-only operational summary. Emits counts and totals, never player identities.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$target = DB::connection();
$source = DB::connection('legacy');
echo 'Mapping sources: '.json_encode($target->table('legacy_player_mappings')->select('source')->selectRaw('COUNT(*) AS total')->groupBy('source')->get()).PHP_EOL;
echo 'Player count: '.$target->table('players')->count().PHP_EOL;
echo 'Admin count: '.$target->table('users')->count().PHP_EOL;
echo 'Wallet totals: '.json_encode($target->table('player_wallet')->selectRaw('COUNT(*) AS wallets, SUM(actual_amount) AS actual, SUM(deposit_amount) AS deposits, SUM(fund_amount) AS funds')->first()).PHP_EOL;
echo 'Wallet transaction totals: '.json_encode($target->table('player_wallet_transactions')->selectRaw('COUNT(*) AS transactions, SUM(amount) AS amount')->first()).PHP_EOL;
echo 'Bets without source players: '.$source->table('ending_bets as b')->leftJoin('players as p', 'b.player_id', '=', 'p.id')->whereNull('p.id')->count().PHP_EOL;
foreach (['ending_bets', 'ending_bet_amounts', 'ending_bet_amount_prizes', 'ending_cards', 'ending_game_results', 'ending_game_winners'] as $table) {
    echo $table.': source='.$source->table($table)->count().' target='.$target->table($table)->count().PHP_EOL;
}
echo 'Quarantined ending bets: '.$target->table('ending_bets')->whereNull('player_id')->where('legacy_player_id', '>', 0)->count().PHP_EOL;
echo 'Ending logs: source='.$app->make(EndingImport::class)->sourceQuery('admin_user_logs')->count().' target='.$target->table('admin_ending_legacy_logs')->count().PHP_EOL;
foreach (['ending_bet_amounts' => 'amount', 'ending_bet_amount_prizes' => 'cash_prize', 'ending_agent_bet_commissions' => 'total_amount'] as $table => $column) {
    echo $table.' monetary total: source='.$source->table($table)->sum($column).' target='.$target->table($table)->sum($column).PHP_EOL;
}
$sample = $target->table('ending_game_winners as w')->join('ending_bets as b', 'w.bet_id', '=', 'b.id')
    ->join('ending_cards as c', 'b.card_id', '=', 'c.id')->join('ending_bet_amounts as a', 'c.bet_amount_id', '=', 'a.id')->value('a.game_id');
if ($sample) {
    $queries = $app->make(EndingQueries::class);
    echo 'MySQL game/winner query check: games='.$queries->games()->whereKey($sample)->count().' winners='.$queries->winners((int) $sample)->count().PHP_EOL;
}
