<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LinkEndingPlayers extends Command
{
    protected $signature = 'ending:link-players {--dry-run : Preview approved player mappings (default)} {--execute : Attach resolved players to previously quarantined bets}';

    protected $description = 'Attach already resolved player mappings to ending history without guessing identities or changing financial data.';

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Choose either --dry-run or --execute.');

            return self::FAILURE;
        }
        $lock = Cache::lock('ending:write', 7200);
        if (! $lock->get()) {
            $this->error('Another ending write or import is running.');

            return self::FAILURE;
        }
        try {
            $eligible = DB::table('ending_bets as b')->join('legacy_player_mappings as m', function ($join): void {
                $join->on('b.legacy_player_mapping_id', '=', 'm.id')->on('b.legacy_player_id', '=', 'm.legacy_player_id');
            })->join('players as player', 'm.player_id', '=', 'player.id')
                ->where('m.source', config('ending.legacy_source'))->where('m.quarantined', false)->whereNull('b.player_id');
            $this->info('Bets with an approved resolved player mapping: '.$eligible->count());
            if (! $this->option('execute')) {
                $this->info('Preview complete. No player links changed.');

                return self::SUCCESS;
            }
            $linked = 0;
            $eligible->select('b.id')->chunkById(500, function ($rows) use (&$linked): void {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, &$linked): void {
                        $bet = DB::table('ending_bets')->where('id', $row->id)->lockForUpdate()->first();
                        if (! $bet || $bet->player_id !== null) {
                            return;
                        }
                        $mapping = DB::table('legacy_player_mappings')->where('id', $bet->legacy_player_mapping_id)
                            ->where('legacy_player_id', $bet->legacy_player_id)->where('source', config('ending.legacy_source'))
                            ->where('quarantined', false)->whereNotNull('player_id')->lockForUpdate()->first();
                        if (! $mapping || ! DB::table('players')->where('id', $mapping->player_id)->exists()) {
                            return;
                        }
                        $game = DB::table('ending_cards as c')->join('ending_bet_amounts as a', 'c.bet_amount_id', '=', 'a.id')
                            ->where('c.id', $bet->card_id)->value('a.game_id');
                        // Retain orphan history for review rather than inventing a game link.
                        if ($game === null) {
                            return;
                        }
                        DB::table('ending_bets')->where('id', $bet->id)->whereNull('player_id')->update(['player_id' => $mapping->player_id]);
                        DB::table('admin_ending_logs')->insert([
                            'game_id' => $game, 'user_id' => null, 'action' => 'link_resolved_player', 'subject_type' => 'bet', 'subject_id' => $bet->id,
                            'before' => json_encode(['player_id' => null], JSON_THROW_ON_ERROR),
                            'after' => json_encode(['player_id' => $mapping->player_id, 'mapping_id' => $mapping->id], JSON_THROW_ON_ERROR),
                            'reason' => 'Attached the existing approved legacy player mapping.', 'created_at' => now(),
                        ]);
                        $linked++;
                    }, 3);
                }
            }, 'b.id', 'id');
            $this->info("Linked {$linked} ending bets. Amounts, results and prize-release flags were preserved.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
