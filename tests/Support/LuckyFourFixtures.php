<?php

namespace Tests\Support;

use App\Models\EndingGame;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

trait LuckyFourFixtures
{
    use EndingFixtures;

    private function luckyFixtures(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(0, 0));
        $this->endingFixtures();
        config(['players.legacy_source' => 'gamexsports']);
        Schema::create('player_wallet', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('player_id')->unique();
            $t->decimal('actual_amount', 10, 2);
        });
        Schema::create('player_wallet_transaction_types', function (Blueprint $t): void {
            $t->id();
            $t->string('wallet_transaction_type');
            $t->string('reference_table');
        });
        Schema::create('player_wallet_transactions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('player_wallet_id');
            $t->unsignedBigInteger('player_id');
            $t->unsignedBigInteger('transaction_type_id');
            $t->unsignedBigInteger('reference_id');
            $t->text('description');
            $t->decimal('amount', 10, 2);
            $t->dateTime('created_at');
        });
        DB::table('player_wallet')->insert(['player_id' => 9, 'actual_amount' => '100.00']);
        DB::table('lucky_four_prizes_settings')->insert(['id' => 1, 'jackpot_prize' => '1000.00', 'three_digits_prize' => '50.00', 'initial_amount' => '800.00', 'percentage_deduction' => '40.00', 'bet_amount' => '20.00']);
        DB::table('admin_lucky_four_imports')->insert(['source' => 'gamexsports', 'report' => '{}', 'verified_at' => now()]);
    }

    private function luckyAdmin(?array $permissions = null): User
    {
        $user = $this->endingAdmin();
        foreach ($permissions ?? ['Lucky Four', 'Lucky Four Enable Disable', 'Lucky Four Prizes Settings', 'Lucky Four Winner Verification', 'Lucky Four Winner Approval'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function luckyGame(bool $finished = true): int
    {
        $game = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2, 'schedule' => $finished ? now()->subDay() : now()->addDay(),
            'is_lucky_four' => 'Yes', 'betting' => 'Disabled', 'game_status_id' => $finished ? 5 : null, 'legacy_checksum' => str_repeat('a', 64)]);
        DB::table('lucky_four_prizes')->insert(['game_id' => $game->id, 'jackpot_prize' => '1000.00', 'three_digits_prize' => '50.00', 'initial_amount' => '1000.00', 'percentage_deduction' => '40.00', 'bet_amount' => '20.00']);
        if ($finished) {
            foreach ([0, 1, 2, 3] as $quarter => $digit) {
                DB::table('ending_game_results')->insert(['game_id' => $game->id, 'quarter_id' => $quarter + 1, 'team_a_score' => 20 + $digit, 'team_b_score' => 10, 'slot_id' => 1, 'lucky_four_result' => $digit]);
            }
        }

        return $game->id;
    }

    private function luckyBet(int $game, string $combination = '0123', string $pool = 'Investment', ?int $player = 9): int
    {
        $amount = DB::table('ending_bet_amounts')->insertGetId(['game_id' => $game, 'amount' => '20.00', 'bet_type_id' => 1]);
        $card = DB::table('ending_cards')->insertGetId(['bet_amount_id' => $amount]);
        $bet = DB::table('ending_bets')->insertGetId(['player_id' => $player, 'legacy_player_id' => 73, 'legacy_player_mapping_id' => 100,
            'card_id' => $card, 'slot_id' => 1, 'created_at' => '2026-10-31 16:30:00', 'legacy_checksum' => str_repeat('b', 64)]);
        DB::table('lucky_four_bets')->insert(['ending_bet_id' => $bet, 'game_id' => $game, 'combination' => $combination, 'amount' => '20.00', 'is_added' => $pool]);

        return $bet;
    }
}
