<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ImportGames extends Command
{
    protected $signature = 'legacy:games {--dry-run : Inspect without writing (default)} {--execute : Copy and verify the game catalog} {--verify : Compare imported game records}';

    protected $description = 'Import the legacy games catalog while preserving its identifiers and fields.';

    private const COLUMNS = ['id', 'game', 'description', 'logo', 'is_active', 'route'];

    public function handle(): int
    {
        $modes = (int) (bool) $this->option('dry-run') + (int) (bool) $this->option('execute') + (int) (bool) $this->option('verify');
        if ($modes > 1) {
            $this->error('Choose only one import mode.');

            return self::FAILURE;
        }
        $lock = Cache::lock('games:import', 300);
        if (! $lock->get()) {
            $this->error('A games catalog import is already running.');

            return self::FAILURE;
        }
        try {
            if (! Schema::hasTable('games') || ! Schema::connection('legacy')->hasTable('games')) {
                throw new RuntimeException('The source and target games tables are required. Run the target migrations first.');
            }
            $rows = DB::connection('legacy')->table('games')->select(self::COLUMNS)->orderBy('id')->get();
            $records = [];
            foreach ($rows as $row) {
                $data = (array) $row;
                $data['id'] = (int) $data['id'];
                $records[] = $data + ['legacy_checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
            }
            $execute = (bool) $this->option('execute');
            $verify = (bool) $this->option('verify');
            $report = DB::transaction(function () use ($records, $execute, $verify): array {
                $report = $this->compare($records, $verify);
                if ($execute) {
                    foreach (array_chunk($report['missing'], 100) as $part) {
                        DB::table('games')->insert($part);
                    }
                    $this->compare($records, true);
                }

                return $report;
            });
            if ($execute) {
                $this->preserveSequence();
            }
            $count = count($records);
            $this->line('games: source='.$count.' matching='.$report['matching'].' missing='.count($report['missing']));
            $this->info($execute || $verify ? "Verified {$count} game catalog records. The legacy database was not changed." : 'Dry run complete. No records changed.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception instanceof QueryException ? 'Game catalog import failed. Check schema compatibility and database access.' : $exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /** @param list<array<string, mixed>> $records
     * @return array{matching: int, missing: list<array<string, mixed>>}
     */
    private function compare(array $records, bool $verify): array
    {
        $existing = DB::table('games')->whereIn('id', array_column($records, 'id'))->get()->keyBy('id');
        $report = ['matching' => 0, 'missing' => []];
        foreach ($records as $record) {
            $old = $existing->get($record['id']);
            if (! $old) {
                if ($verify) {
                    throw new RuntimeException('Missing game catalog record '.$record['id'].'.');
                }
                $report['missing'][] = $record;

                continue;
            }
            foreach ($record as $column => $value) {
                $actual = $column === 'id' ? (int) $old->{$column} : $old->{$column};
                if ($actual !== $value) {
                    throw new RuntimeException('Game catalog conflict for record '.$record['id'].', field '.$column.'. No existing record was overwritten.');
                }
            }
            $report['matching']++;
        }
        if (DB::table('games')->whereNotNull('legacy_checksum')->whereNotIn('id', array_column($records, 'id'))->exists()) {
            throw new RuntimeException('Previously imported games are missing from the source. No history was deleted.');
        }

        return $report;
    }

    private function preserveSequence(): void
    {
        $source = DB::connection('legacy');
        $target = DB::connection();
        if ($source->getDriverName() !== 'mysql' || $target->getDriverName() !== 'mysql') {
            return;
        }
        $sourceNext = $source->table('information_schema.TABLES')->where('TABLE_SCHEMA', $source->getDatabaseName())
            ->where('TABLE_NAME', 'games')->value('AUTO_INCREMENT');
        $targetNext = $target->table('information_schema.TABLES')->where('TABLE_SCHEMA', $target->getDatabaseName())
            ->where('TABLE_NAME', 'games')->value('AUTO_INCREMENT');
        $next = max((int) $sourceNext, (int) $targetNext, (int) DB::table('games')->max('id') + 1);
        DB::statement("ALTER TABLE games AUTO_INCREMENT = {$next}");
    }
}
