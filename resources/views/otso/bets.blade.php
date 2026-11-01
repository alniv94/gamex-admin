<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #111827;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .section-title {
            margin: 18px 0 8px;
            color: #3f51b5;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .label {
            width: 22%;
            color: #6b7280;
            padding: 4px 8px 4px 0;
            vertical-align: top;
        }

        .value {
            padding: 4px 0;
        }

        .bets-table,
        .bets-table th,
        .bets-table td {
            border: 1px solid #d1d5db;
        }

        .bets-table th,
        .bets-table td {
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }

        .bets-table th {
            background: #f3f4f6;
            font-size: 11px;
            text-transform: uppercase;
        }

        .muted {
            color: #6b7280;
        }

        .nowrap {
            white-space: nowrap;
        }
    </style>
</head>

<body>
    <table>
        <tr>
            <td class="label">Generation Datetime</td>
            <td class="value"><?= e($generated_date) ?></td>
        </tr>
    </table>

    <div class="section-title">Game Details</div>
    <table>
        <tr>
            <td class="label">Game Type</td>
            <td class="value">Otso Panalo</td>
        </tr>
        <tr>
            <td class="label">Game ID</td>
            <td class="value">#<?= e($details['id'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="label">League</td>
            <td class="value"><?= e($details['league'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="label">Matchup</td>
            <td class="value">
                <?= e(trim(($details['team_a_name'] ?? '') . ' ' . ($details['team_a_alias'] ?? ''))) ?>
                vs
                <?= e(trim(($details['team_b_name'] ?? '') . ' ' . ($details['team_b_alias'] ?? ''))) ?>
            </td>
        </tr>
        <tr>
            <td class="label">Ending Game ID</td>
            <td class="value">#<?= e($details['ending_game_id'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="label">Schedule</td>
            <td class="value"><?= e($schedule) ?></td>
        </tr>
        <tr>
            <td class="label">Betting Deadline</td>
            <td class="value"><?= e($betting_deadline) ?></td>
        </tr>
        <tr>
            <td class="label">Total Bets</td>
            <td class="value"><?= number_format(count($bets)) ?></td>
        </tr>
        <tr>
            <td class="label">Total Bet Amount</td>
            <td class="value">PHP <?= number_format((float) ($total_bet_amount ?? 0), 2) ?></td>
        </tr>
    </table>

    <div class="section-title">Bets</div>
    <table class="bets-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Player Name</th>
                <th>Bet Type</th>
                <th>Ticket Name</th>
                <th>Combinations</th>
                <th>Amount</th>
                <th>Bet At</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($bets)) : ?>
                <?php foreach ($bets as $bet) : ?>
                    <tr>
                        <td class="nowrap">#<?= e($bet['id'] ?? '-') ?></td>
                        <td><?= e(($bet['username'] ?? $bet['player_name'] ?? '') ?: '-') ?></td>
                        <td><?= e($bet['bet_type'] ?: '-') ?></td>
                        <td><?= e(($bet['name'] ?? '') ?: '-') ?></td>
                        <td>
                            <div>Q1: <?= e($bet['first_quarter_combination'] ?: '-') ?></div>
                            <div>Q2: <?= e($bet['second_quarter_combination'] ?: '-') ?></div>
                            <div>Q3: <?= e($bet['third_quarter_combination'] ?: '-') ?></div>
                            <div>Q4: <?= e($bet['fourth_quarter_combination'] ?: '-') ?></div>
                        </td>
                        <td class="nowrap">PHP <?= number_format((float) ($bet['amount'] ?? 0), 2) ?></td>
                        <td class="nowrap"><?= !empty($bet['created_at']) ? e(\Carbon\CarbonImmutable::parse($bet['created_at'], 'UTC')->timezone('Asia/Manila')->format('M d, Y h:i A')) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="7" style="text-align: center;">No bets found for this game.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>

</html>
