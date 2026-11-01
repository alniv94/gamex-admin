<?php

namespace App\Http\Controllers;

use App\Http\Requests\EndingReportRequest;
use App\Services\Ending\EndingReportQueries;
use App\Services\Ending\EndingReports;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EndingReportController extends Controller
{
    public function __construct(private EndingReportQueries $queries) {}

    public function index(EndingReportRequest $request, string $report): Response
    {
        $filters = $request->validated();
        $columns = EndingReports::columns($report);

        return Inertia::render('ending/reports/Report', [
            'report' => $report,
            'title' => EndingReports::DEFINITIONS[$report][0],
            'columns' => $columns,
            'filters' => $filters,
            'records' => fn () => $this->queries->filtered($report, $filters)->paginate($filters['per_page'])->withQueryString(),
            'totals' => Inertia::defer(function () use ($report, $filters, $columns): array {
                $query = $this->queries->filtered($report, $filters)->reorder();
                $totals = $query->newQuery()->fromSub($query, 'totals');
                $keys = [];
                foreach ($columns as $column) {
                    if ($column['format'] === 'money') {
                        $keys[] = $column['key'];
                        $totals->selectRaw('COALESCE(SUM('.$column['key'].'), 0) as '.$column['key']);
                    }
                }

                return $keys === [] ? [] : (array) $totals->first();
            }),
            'leagues' => fn () => DB::table('ending_leagues')->orderBy('league')->get(['id', 'league']),
            'teams' => fn () => DB::table('ending_teams')->orderBy('team_name')->get(['id', 'league_id', 'team_name']),
            'today' => now('Asia/Manila')->toDateString(),
        ]);
    }

    public function export(EndingReportRequest $request, string $report): StreamedResponse|HttpResponse
    {
        $filters = $request->validated();
        $columns = EndingReports::columns($report);
        $query = $this->queries->filtered($report, $filters);
        $filename = 'ending-'.$report.'-'.$filters['date_from'].'-to-'.$filters['date_to'].'-'.$filters['scope'];

        if ($filters['format'] === 'pdf') {
            if ($filters['scope'] !== 'page') {
                throw ValidationException::withMessages(['scope' => 'PDF exports support the current page. Use CSV to export all matching records.']);
            }
            $rows = $query->forPage($filters['page'], $filters['per_page'])->get()->map(function (object $row) use ($columns): array {
                $values = [];
                foreach ($columns as $column) {
                    $values[] = $this->value($row->{$column['key']} ?? null, $column['format']);
                }

                return $values;
            });
            $pdf = new Dompdf(['isRemoteEnabled' => false]);
            $pdf->loadHtml(view('ending.report', ['title' => EndingReports::DEFINITIONS[$report][0], 'columns' => $columns, 'rows' => $rows, 'filters' => $filters])->render());
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
                foreach ($this->exportRows($query, $filters) as $row) {
                    $values = [];
                    foreach ($columns as $column) {
                        $value = $this->value($row->{$column['key']} ?? null, $column['format']);
                        $values[] = $column['format'] === 'text' && preg_match('/^[\s\x00-\x1F]*[=+@-]/u', $value) ? "'".$value : $value;
                    }
                    fputcsv($stream, $values, escape: '');
                }
            } finally {
                fclose($stream);
            }
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Accel-Buffering' => 'no', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return \Generator<int, object>
     */
    private function exportRows(Builder $query, array $filters): \Generator
    {
        if ($filters['scope'] === 'page') {
            yield from $query->forPage($filters['page'], $filters['per_page'])->get();

            return;
        }
        $last = null;
        $sort = $filters['sort'];
        $operator = $filters['direction'] === 'asc' ? '>' : '<';
        do {
            $chunk = clone $query;
            if ($last !== null) {
                $chunk->where(function (Builder $q) use ($last, $sort, $operator): void {
                    $value = $last->{$sort};
                    if ($value === null) {
                        $q->where(fn (Builder $same) => $same->whereNull($sort)->where('id', $operator, $last->id));
                        if ($operator === '>') {
                            $q->orWhereNotNull($sort);
                        }
                    } else {
                        $q->where($sort, $operator, $value)->orWhere(fn (Builder $same) => $same->where($sort, $value)->where('id', $operator, $last->id));
                        if ($operator === '<') {
                            $q->orWhereNull($sort);
                        }
                    }
                });
            }
            $rows = $chunk->limit(1000)->get();
            yield from $rows;
            $last = $rows->last();
        } while ($rows->count() === 1000);
    }

    private function value(mixed $value, string $format): string
    {
        if ($format === 'date' && $value !== null) {
            return CarbonImmutable::parse($value, 'UTC')->setTimezone('Asia/Manila')->format('Y-m-d H:i:s');
        }

        return $value === null ? '' : (string) $value;
    }
}
