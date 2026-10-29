<?php

namespace App\Providers;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class DatabaseSafetyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $source = config('database.connections.legacy.database');
        $default = config('database.default');
        if ($default === 'legacy' || config("database.connections.{$default}.database") === $source) {
            throw new RuntimeException('The admin target must be separate from the legacy source database.');
        }
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($source): void {
            $connection = $event->connection;
            if ($connection->getName() !== 'legacy' && $connection->getDatabaseName() === $source) {
                throw new RuntimeException('Only the protected legacy connection may access the source database.');
            }
            if ($connection->getName() !== 'legacy') {
                return;
            }
            $connection->beforeExecuting(function (string $sql): void {
                $sql = trim($sql);
                $snapshot = in_array(strtoupper($sql), [
                    'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
                    'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY',
                    'ROLLBACK',
                ], true);
                if ((! preg_match('/\\A(?:SELECT|SHOW|EXPLAIN)\\b/i', $sql) && ! $snapshot)
                    || preg_match('/\\bINTO\\s+(?:OUTFILE|DUMPFILE)\\b|;\\s*\\S|\\bFOR\\s+UPDATE\\b/i', $sql)) {
                    throw new RuntimeException('The legacy database connection is read-only.');
                }
            });
        });
    }
}
