<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\EndingFixtures;
use Tests\TestCase;

class OutletManagementTest extends TestCase
{
    use EndingFixtures, RefreshDatabase;

    private function locations(): void
    {
        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('barangays', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('city_id');
        });
        DB::table('cities')->insert([['id' => 220, 'name' => 'BAGAC'], ['id' => 221, 'name' => 'BALANGA']]);
        DB::table('barangays')->insert([
            ['id' => 5590, 'name' => 'Ibaba', 'city_id' => 220],
            ['id' => 5591, 'name' => 'Central', 'city_id' => 221],
        ]);
    }

    private function manager(): void
    {
        $this->endingAdmin([])->givePermissionTo('Outlets');
        $this->locations();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['outlet_name' => 'Las Casas Bagac Bataan', 'city_id' => 220, 'barangay_id' => 5590,
            'address' => 'Hotel de Oriente bldg', 'is_active' => 'Active'];
    }

    private function outlet(): int
    {
        return DB::table('admin_outlets')->insertGetId([
            ...array_diff_key($this->payload(), ['city_id' => true]),
            'created_at' => '2022-05-18 03:00:23', 'legacy_checksum' => str_repeat('a', 64),
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/settings/outlets')->assertRedirect('/login');
    }

    public function test_administrators_without_outlets_permission_receive_403(): void
    {
        $this->endingAdmin([15]);

        $this->get('/settings/outlets')->assertForbidden();
        $this->getJson('/settings/outlets/barangays?city_id=220')->assertForbidden();
        $this->post('/settings/outlets', $this->payload())->assertForbidden();
        $this->put('/settings/outlets/1', [...$this->payload(), 'version' => 0])->assertForbidden();
        $this->assertDatabaseCount('admin_outlet_audits', 0);
    }

    public function test_lists_and_filters_outlets_using_the_new_geographic_schema(): void
    {
        $this->manager();
        $this->outlet();
        DB::table('admin_outlets')->insert(['outlet_name' => 'Closed outlet', 'is_active' => 'Inactive']);

        $this->get('/settings/outlets?search=Ibaba&status=Active&per_page=5&sort=outlet_name&direction=asc')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('settings/Outlets')
            ->has('outlets.data', 1)->where('outlets.total', 1)->where('outlets.per_page', 5)
            ->where('outlets.data.0.outlet_name', 'Las Casas Bagac Bataan')
            ->where('outlets.data.0.city', 'BAGAC')->where('outlets.data.0.barangay', 'Ibaba')
            ->where('outlets.data.0.created_at', '2022-05-18 03:00:23')
            ->missing('outlets.data.0.legacy_checksum')->has('cities', 2));
    }

    public function test_sorting_and_pagination_apply_to_the_complete_result(): void
    {
        $this->manager();
        foreach (['Zulu', 'Echo', 'Alpha', 'Bravo', 'Charlie', 'Delta'] as $name) {
            DB::table('admin_outlets')->insert(['outlet_name' => $name]);
        }

        $this->get('/settings/outlets?sort=outlet_name&direction=asc&per_page=5&page=2')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('outlets.total', 6)
            ->has('outlets.data', 1)->where('outlets.data.0.outlet_name', 'Zulu'));
    }

    public function test_barangay_options_are_scoped_to_the_selected_city(): void
    {
        $this->manager();

        $this->getJson('/settings/outlets/barangays?city_id=220')->assertOk()
            ->assertExactJson(['records' => [['id' => 5590, 'name' => 'Ibaba']]]);
    }

    public function test_creates_an_audited_outlet_without_accepting_legacy_metadata(): void
    {
        $this->manager();

        $this->from('/settings/outlets')->post('/settings/outlets', [
            ...$this->payload(), 'id' => 900, 'created_at' => '1999-01-01', 'legacy_checksum' => 'forged',
            'management_version' => 900,
        ])->assertRedirect('/settings/outlets')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admin_outlets', ['id' => 1, 'outlet_name' => 'Las Casas Bagac Bataan',
            'barangay_id' => 5590, 'is_active' => 'Active', 'management_version' => 1, 'legacy_checksum' => null]);
        $this->assertDatabaseHas('admin_outlet_audits', ['outlet_id' => 1, 'action' => 'created']);
    }

    public function test_updates_status_and_address_while_retaining_identity_and_creation_date(): void
    {
        $this->manager();
        $id = $this->outlet();
        DB::table('admin_investors')->insert(['outlet_id' => $id, 'first_name' => 'Linked investor']);

        $this->from('/settings/outlets')->put('/settings/outlets/'.$id, [
            ...$this->payload(), 'version' => 0, 'address' => 'Updated address', 'is_active' => 'Inactive',
        ])->assertRedirect('/settings/outlets')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admin_outlets', ['id' => $id, 'is_active' => 'Inactive', 'address' => 'Updated address',
            'created_at' => '2022-05-18 03:00:23', 'legacy_checksum' => str_repeat('a', 64), 'management_version' => 1]);
        $this->assertDatabaseHas('admin_investors', ['outlet_id' => $id]);
        $this->assertDatabaseHas('admin_outlet_audits', ['outlet_id' => $id, 'action' => 'updated']);
    }

    public function test_duplicate_names_in_the_same_barangay_are_rejected_on_create_and_update(): void
    {
        $this->manager();
        $this->outlet();
        $other = DB::table('admin_outlets')->insertGetId(['outlet_name' => 'Other outlet', 'barangay_id' => 5590]);

        $this->post('/settings/outlets', $this->payload())->assertSessionHasErrors([
            'outlet_name' => 'An outlet with this name already exists in the selected barangay.',
        ]);
        $this->put('/settings/outlets/'.$other, [...$this->payload(), 'version' => 0])->assertSessionHasErrors('outlet_name');
        $this->assertDatabaseHas('admin_outlets', ['id' => $other, 'outlet_name' => 'Other outlet']);
        $this->assertDatabaseCount('admin_outlet_audits', 0);
    }

    public function test_stale_edits_are_rejected_without_overwriting_the_current_record(): void
    {
        $this->manager();
        $id = $this->outlet();
        DB::table('admin_outlets')->where('id', $id)->update(['management_version' => 2]);

        $this->put('/settings/outlets/'.$id, [...$this->payload(), 'version' => 0, 'is_active' => 'Inactive'])
            ->assertSessionHasErrors('version');

        $this->assertDatabaseHas('admin_outlets', ['id' => $id, 'is_active' => 'Active', 'management_version' => 2]);
        $this->assertDatabaseCount('admin_outlet_audits', 0);
    }

    #[TestWith(['outlet_name', 'A'])]
    #[TestWith(['outlet_name', ''])]
    #[TestWith(['address', '1234'])]
    #[TestWith(['barangay_id', 5591])]
    #[TestWith(['city_id', 999])]
    #[TestWith(['is_active', 'Pending'])]
    public function test_invalid_outlet_fields_do_not_create_records(string $field, mixed $value): void
    {
        $this->manager();

        $this->post('/settings/outlets', [...$this->payload(), $field => $value])->assertSessionHasErrors($field);

        $this->assertDatabaseCount('admin_outlets', 0);
        $this->assertDatabaseCount('admin_outlet_audits', 0);
    }

    public function test_missing_outlets_return_404_and_unsafe_sort_columns_are_rejected(): void
    {
        $this->manager();

        $this->put('/settings/outlets/999', [...$this->payload(), 'version' => 0])->assertNotFound();
        $this->get('/settings/outlets?sort=legacy_checksum')->assertSessionHasErrors('sort');

        $this->assertDatabaseCount('admin_outlet_audits', 0);
    }
}
