<?php

namespace App\Services\OtsoPanalo;

use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use stdClass;

class OtsoPanaloPdf
{
    public function __construct(private OtsoPanaloQueries $queries) {}

    public function render(stdClass $game): string
    {
        $bets = $this->queries->filteredBets(['game_id' => $game->id])->get()->map(fn ($row) => (array) $row)->all();
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setChroot(resource_path('views/otso'));
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('otso.bets', [
            'generated_date' => now()->timezone('Asia/Manila')->format('M d, Y h:i A'),
            'details' => (array) $game, 'bets' => $bets,
            'schedule' => $game->schedule ? CarbonImmutable::parse($game->schedule, 'UTC')->timezone('Asia/Manila')->format('M d, Y h:i A') : '-',
            'betting_deadline' => $game->betting_deadline ? CarbonImmutable::parse($game->betting_deadline, 'UTC')->timezone('Asia/Manila')->format('M d, Y h:i A') : '-',
            'total_bet_amount' => array_sum(array_column($bets, 'amount')),
        ])->render());
        $pdf->setPaper('legal', 'portrait');
        $pdf->render();
        $pdf->getCanvas()->page_text(450, 986, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('Helvetica'), 10);

        return $pdf->output();
    }
}
