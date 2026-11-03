<?php

namespace App\Http\Controllers;

use App\Http\Requests\PagcorReportRequest;
use App\Services\Pagcor\PagcorReportQueries;
use App\Services\Pagcor\PagcorReports;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PagcorReportController extends Controller
{
    public function __construct(private PagcorReportQueries $queries) {}

    public function index(PagcorReportRequest $request, string $report): Response
    {
        $filters = $request->validated();

        return Inertia::render('pagcor/Report', [
            'report' => $report,
            'title' => PagcorReports::DEFINITIONS[$report][0],
            'columns' => PagcorReports::columns($report),
            'filters' => $filters,
            'records' => fn () => $this->queries->filtered($report, $filters)->paginate($filters['per_page'])->withQueryString(),
            'totals' => Inertia::defer(function () use ($report, $filters): array {
                if (! $filters['submitted']) {
                    return [];
                }
                $query = DB::query()->fromSub($this->queries->filtered($report, $filters)->reorder(), 'totals');
                foreach (PagcorReports::columns($report) as $column) {
                    if ($column['format'] === 'money' || $column['key'] === 'games') {
                        $query->selectRaw('COALESCE(SUM('.$column['key'].'), 0) as '.$column['key']);
                    }
                }

                return (array) $query->first();
            }),
            'leagues' => fn () => DB::table('ending_leagues')->orderBy('league')->get(['id', 'league']),
            'teams' => fn () => DB::table('ending_teams')->orderBy('team_name')->get(['id', 'league_id', 'team_name']),
        ]);
    }

    public function export(PagcorReportRequest $request, string $report): StreamedResponse|HttpResponse
    {
        $filters = $request->validated();
        $columns = PagcorReports::columns($report);
        $query = $this->queries->filtered($report, $filters);
        $title = PagcorReports::DEFINITIONS[$report][0];
        $group = 'PAGCOR Reports';
        $filters['filter_by'] = $report === 'transactional' ? 'bet' : 'scheduled';
        $filename = 'pagcor-'.$report.'-'.$filters['date_from'].'-to-'.$filters['date_to'].'-'.$filters['scope'];
        if ($filters['scope'] === 'page') {
            $query->forPage($filters['page'], $filters['per_page']);
        }
        if ($filters['format'] === 'pdf') {
            if ($filters['scope'] !== 'page') {
                throw ValidationException::withMessages(['scope' => 'PDF exports support the current page. Use CSV to export all matching records.']);
            }
            $rows = $query->get()->map(fn (object $row): array => $this->values($row, $columns));
            $pdf = new Dompdf(['isRemoteEnabled' => false]);
            $pdf->loadHtml(view('ending.report', compact('title', 'group', 'columns', 'rows', 'filters'))->render());
            $pdf->setPaper('a3', 'landscape');
            $pdf->render();

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"']);
        }

        return response()->streamDownload(function () use ($query, $columns, $filters): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('The export stream could not be opened.');
            }
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, array_column($columns, 'title'), escape: '');
                $rows = $filters['scope'] === 'page' ? $query->get() : $query->lazy(1000);
                foreach ($rows as $row) {
                    $values = $this->values($row, $columns);
                    foreach ($columns as $index => $column) {
                        if ($column['format'] === 'text' && preg_match('/^[\s\x00-\x1F]*[=+@-]/u', $values[$index])) {
                            $values[$index] = "'".$values[$index];
                        }
                    }
                    fputcsv($stream, $values, escape: '');
                }
            } finally {
                fclose($stream);
            }
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Accel-Buffering' => 'no', 'Cache-Control' => 'private, no-store']);
    }

    /** @param list<array{key: string, title: string, format: string}> $columns
     * @return list<string>
     */
    private function values(object $row, array $columns): array
    {
        $inactive = isset($row->games) ? (int) $row->games === 0 : ($row->game_id ?? $row->transaction_id ?? null) === null;
        $values = [];
        foreach ($columns as $index => $column) {
            $value = $row->{$column['key']} ?? null;
            if ($inactive) {
                $value = $index === 0 ? ($row->period ?? $row->day ?? '') : ($index === 1 ? 'No Activity' : null);
            } elseif ($column['format'] === 'date' && $value !== null) {
                $value = CarbonImmutable::parse($value, 'UTC')->setTimezone('Asia/Manila')->format('Y-m-d H:i:s');
            }
            $values[] = $value === null ? '' : (string) $value;
        }

        return $values;
    }
}
