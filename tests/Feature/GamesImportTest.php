<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class GamesImportTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    private PDO $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = tempnam(sys_get_temp_dir(), 'games-source-');
        $this->source = new PDO('sqlite:'.$this->fixture);
        $this->source->exec('CREATE TABLE games (id INTEGER PRIMARY KEY, game VARCHAR(155), description TEXT, logo VARCHAR(155), is_active VARCHAR(16), route VARCHAR(155))');
        $insert = $this->source->prepare('INSERT INTO games VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([1, 'Happy Ending', 'Bet on your two lucky numbers.', 'happy-ending.png', 'Active', 'EndingGames']);
        $insert->execute([2, 'Lucky Four', 'Pick four digits — every quarter counts.', 'lucky-four.webp', 'Active', 'LuckyFourGames']);
        $insert->execute([3, 'Powerball', null, 'powerball.webp', 'Inactive', 'PowerBallGames']);
        $insert->execute([5, 'Otso Panalo', 'Predict both teams’ last digits.', 'basketball-ending.webp', 'Active', 'OtsoPanaloGames']);
        $insert->execute([6, 'Happy Ending Max', null, null, 'Active', 'HappyEndingMax']);
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => $this->fixture, 'prefix' => '']]);
        DB::purge('legacy');
    }

    protected function tearDown(): void
    {
        DB::disconnect('legacy');
        unset($this->source);
        if (isset($this->fixture)) {
            unlink($this->fixture);
        }
        parent::tearDown();
    }

    public function test_default_dry_run_and_verifying_missing_rows_never_import_records(): void
    {
        $this->artisan('legacy:games')->assertSuccessful();
        $this->artisan('legacy:games', ['--verify' => true])->assertFailed();

        $this->assertDatabaseCount('games', 0);
        $this->assertSame(5, (int) $this->source->query('SELECT COUNT(*) FROM games')->fetchColumn());
    }

    public function test_import_preserves_every_field_and_original_ids_without_duplicates_or_source_changes(): void
    {
        $before = $this->source->query('SELECT * FROM games ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        DB::table('games')->insert(['id' => 10, 'game' => 'New catalog entry']);

        $this->artisan('legacy:games', ['--execute' => true])->assertSuccessful();
        $this->artisan('legacy:games', ['--execute' => true])->assertSuccessful();
        $this->artisan('legacy:games', ['--verify' => true])->assertSuccessful();

        $imported = DB::table('games')->whereNotNull('legacy_checksum')->orderBy('id')->get();
        $this->assertSame([1, 2, 3, 5, 6], $imported->pluck('id')->all());
        foreach ($before as $record) {
            $this->assertDatabaseHas('games', $record);
        }
        $this->assertTrue($imported->every(fn (object $row): bool => strlen($row->legacy_checksum) === 64));
        $this->assertDatabaseCount('games', 6);
        $this->assertDatabaseHas('games', ['id' => 10, 'game' => 'New catalog entry', 'legacy_checksum' => null]);
        $this->assertSame($before, $this->source->query('SELECT * FROM games ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function test_a_conflict_prevents_all_inserts_and_preserves_existing_records(): void
    {
        DB::table('games')->insert(['id' => 5, 'game' => 'Existing game']);

        $this->artisan('legacy:games', ['--execute' => true])->assertFailed();

        $this->assertDatabaseCount('games', 1);
        $this->assertDatabaseHas('games', ['id' => 5, 'game' => 'Existing game', 'legacy_checksum' => null]);
        $this->assertDatabaseMissing('games', ['id' => 1]);
    }

    public function test_verification_detects_source_and_target_changes_without_overwriting_them(): void
    {
        $this->artisan('legacy:games', ['--execute' => true])->assertSuccessful();
        $this->source->exec("UPDATE games SET game = 'Changed in legacy' WHERE id = 2");

        $this->artisan('legacy:games', ['--verify' => true])->assertFailed();
        $this->artisan('legacy:games', ['--execute' => true])->assertFailed();
        $this->assertDatabaseHas('games', ['id' => 2, 'game' => 'Lucky Four']);

        $this->source->exec("UPDATE games SET game = 'Lucky Four' WHERE id = 2");
        DB::table('games')->where('id', 2)->update(['route' => 'UpdatedRoute']);

        $this->artisan('legacy:games', ['--verify' => true])->assertFailed();
        $this->artisan('legacy:games', ['--execute' => true])->assertFailed();
        $this->assertDatabaseHas('games', ['id' => 2, 'route' => 'UpdatedRoute']);
    }

    public function test_source_deletions_are_reported_without_deleting_imported_history(): void
    {
        $this->artisan('legacy:games', ['--execute' => true])->assertSuccessful();
        $this->source->exec('DELETE FROM games WHERE id = 6');

        $this->artisan('legacy:games', ['--verify' => true])->assertFailed();
        $this->artisan('legacy:games', ['--execute' => true])->assertFailed();

        $this->assertDatabaseCount('games', 5);
        $this->assertDatabaseHas('games', ['id' => 6, 'game' => 'Happy Ending Max']);
    }

    public function test_conflicting_modes_do_not_write_any_records(): void
    {
        $this->artisan('legacy:games', ['--dry-run' => true, '--execute' => true])->assertFailed();

        $this->assertDatabaseCount('games', 0);
    }
}
