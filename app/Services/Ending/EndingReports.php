<?php

namespace App\Services\Ending;

class EndingReports
{
    public const DEFINITIONS = [
        'bets' => ['Bets', 'Ending Report Bets', 'mdi-hand-coin-outline'],
        'game-card' => ['Game Card', 'Ending Report Cards', 'mdi-file-document-outline'],
        'game' => ['Game', 'Game Report', 'mdi-basketball'],
        'progress' => ['Progress', 'Progress Report', 'mdi-chart-bar'],
        'transactional' => ['Transactional', 'Transactional Report', 'mdi-receipt-text-outline'],
        'cash-in' => ['Cash In', 'Cash In Report', 'mdi-cash-plus'],
        'cash-out' => ['Cash Out', 'Cash Out Report', 'mdi-cash-minus'],
        'cards' => ['Cards', 'Card Type Report', 'mdi-cards-outline'],
        'payouts' => ['Payouts', 'Payout Report', 'mdi-cash-check'],
        'top-winning-combination' => ['Top Winning Combination', 'Top Winning Combination', 'mdi-medal-outline'],
        'master-agents-token' => ["Master Agent's Token", "Master Agent's Token", 'mdi-cash-multiple'],
    ];

    /** @return list<array{key: literal-string, title: string, format: string}> */
    public static function columns(string $report): array
    {
        $game = ['id' => 'Game ID', 'league' => 'League', 'game' => 'Game', 'schedule' => 'Schedule|date'];
        $money = ['gross_sales' => 'Gross Sales|money', 'payout' => 'Payouts|money', 'ggr' => 'Gross Gaming Revenue|money',
            'pagcor_share' => 'PAGCOR Share|money', 'net_income' => 'Net Amount|money'];
        $columns = match ($report) {
            'bets' => ['id' => 'Bet ID', 'player_id' => 'Player ID', 'name' => 'Player Name', 'mobile_number' => 'Mobile Number',
                'league' => 'League', 'game' => 'Game', 'schedule' => 'Schedule|date', 'card_id' => 'Card Number',
                'amount' => 'Bet Amount|money', 'slot' => 'Combination', 'type' => 'Bet Type', 'quarter_id' => 'Quarter',
                'result' => 'Bet Status', 'payout' => 'Payouts|money', 'created_at' => 'Bet At|date'],
            'game-card' => ['id' => 'Card Number', 'league' => 'League', 'game' => 'Game', 'schedule' => 'Schedule|date',
                'type' => 'Bet Type', 'amount' => 'Bet Amount|money', 'bets' => 'Total Bets',
                'gross_sales' => 'Total Bet Amount|money', 'payout' => 'Payouts|money', 'created_at' => 'Created At|date'],
            'game' => $game + ['game_status' => 'Game Status'] + $money,
            'progress' => ['period' => 'Period', 'league' => 'League', 'games' => 'Games', 'bettors' => 'Bettors', 'bets' => 'Bets'] + $money,
            'transactional' => ['id' => 'Transaction ID', 'created_at' => 'Date Time|date', 'transaction_type' => 'Transaction Type',
                'player_id' => 'Player ID', 'name' => 'Name', 'mobile_number' => 'Mobile Number', 'amount' => 'Amount|money',
                'description' => 'Description'],
            'cash-in', 'cash-out' => ['id' => 'Player ID', 'name' => 'Name', 'mobile_number' => 'Mobile Number',
                'city' => 'City / Municipality', 'transactions' => 'Transactions', 'amount' => 'Total Amount|money'],
            'cards' => ['type' => 'Bet Type', 'cards' => 'Cards', 'bets' => 'Bets'] + $money,
            'payouts' => $game + ['traditional' => 'Traditional|money', 'per_quarter' => 'Per Quarter|money', 'payout' => 'Payouts|money'],
            'top-winning-combination' => ['slot' => 'Combination', 'winners' => 'Number of Winners', 'bets' => 'Winning Bets',
                'gross_sales' => 'Winning Bet Amount|money', 'payout' => 'Released Payouts|money'],
            'master-agents-token' => ['id' => 'Agent ID', 'name' => 'Agent', 'token' => 'Current Token Balance|money',
                'players' => 'Assigned Players', 'bets' => 'Total Bets', 'gross_sales' => 'Total Bet Amount|money'],
            default => throw new \InvalidArgumentException('Unknown Ending report.'),
        };

        $result = [];
        foreach ($columns as $key => $label) {
            [$title, $format] = array_pad(explode('|', $label), 2, 'text');
            $result[] = compact('key', 'title', 'format');
        }

        return $result;
    }

    public static function defaultSort(string $report): string
    {
        return match ($report) {
            'bets', 'transactional' => 'created_at',
            'game-card', 'game', 'payouts' => 'schedule',
            'progress' => 'period',
            'top-winning-combination' => 'winners',
            'cash-in', 'cash-out' => 'amount',
            default => 'id',
        };
    }
}
