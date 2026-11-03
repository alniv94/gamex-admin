<?php

namespace App\Services\Pagcor;

class PagcorReports
{
    public const DEFINITIONS = [
        'ending-game' => ['Ending Game', 'Pagcor Game Report', 'mdi-basketball'],
        'lucky-four-game' => ['Lucky Four Game', 'Pagcor Game Report', 'mdi-basketball'],
        'ending-progress' => ['Ending Progress', 'Pagcor Progress Report', 'mdi-chart-bar'],
        'lucky-four-progress' => ['Lucky Four Progress', 'Pagcor Progress Report', 'mdi-chart-bar'],
        'transactional' => ['Transactional', 'Pagcor Transactional Report', 'mdi-receipt-text-outline'],
    ];

    /** @return list<array{key: literal-string, title: string, format: string}> */
    public static function columns(string $report): array
    {
        $lucky = str_starts_with($report, 'lucky-four');
        $money = ['gross_sales' => ($lucky ? 'Gross Amount' : 'Gross Sales').'|money', 'payout' => 'Payouts|money',
            'ggr' => ($lucky ? 'Net Amount' : 'Gross Gaming Revenue').'|money', 'pagcor_share' => 'PAGCOR Share|money'];
        if ($report === 'transactional') {
            $labels = ['date_time' => 'Date Time|date', 'time_of_bet' => 'Time of Bet|date', 'settle_time' => 'Settle Time|date',
                'channel_type' => 'Channel Type', 'transaction_type' => 'Transaction Type', 'transaction_id' => 'Transaction ID',
                'player_id' => 'Player ID', 'amount' => 'Amount|money', 'brand' => 'Brand', 'sport' => 'Sport',
                'ticket_status' => 'Ticket Status', 'prematch_live' => 'Prematch Live', 'kiosk_terminal' => 'Kiosk Terminal',
                'outlet' => 'Outlet', 'game_type' => 'Game Type'];
        } elseif (str_ends_with($report, 'progress')) {
            $labels = ['period' => 'Schedule Date', 'games' => 'Total Games'];
            foreach ($money as $key => $label) {
                $labels[$key] = 'Total '.$label;
            }
        } else {
            $labels = ['league' => 'League', 'game' => 'Game', 'game_status' => 'Game Status', 'schedule' => 'Schedule Date|date'] + $money;
        }
        $columns = [];
        foreach ($labels as $key => $label) {
            [$title, $format] = array_pad(explode('|', $label), 2, 'text');
            $columns[] = compact('key', 'title', 'format');
        }

        return $columns;
    }

    public static function defaultSort(string $report): string
    {
        return $report === 'transactional' ? 'date_time' : (str_ends_with($report, 'progress') ? 'period' : 'schedule');
    }
}
