<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostgresIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Target isolated PostgreSQL test database regardless of active .env driver
        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.host', env('DB_PGSQL_TEST_HOST', '127.0.0.1'));
        Config::set('database.connections.pgsql.port', env('DB_PGSQL_TEST_PORT', '5432'));
        Config::set('database.connections.pgsql.database', env('DB_PGSQL_TEST_DATABASE', env('DB_TEST_DATABASE', 'monitoring_pkl_test')));
        Config::set('database.connections.pgsql.username', env('DB_PGSQL_TEST_USERNAME', 'postgres'));
        Config::set('database.connections.pgsql.password', env('DB_PGSQL_TEST_PASSWORD', 'postgres'));
        Config::set('database.connections.pgsql.charset', 'utf8');
        Config::set('database.connections.pgsql.timezone', 'Asia/Jakarta');

        DB::purge('pgsql');

        try {
            DB::connection('pgsql')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL server not available: ' . $e->getMessage());
        }
    }

    public function test_postgresql_connection_and_timezone_are_active(): void
    {
        $version = DB::connection('pgsql')->select('SELECT version() as v')[0]->v;
        $this->assertStringContainsString('PostgreSQL', $version);

        $now = DB::connection('pgsql')->select('SELECT CURRENT_TIME as t');
        $this->assertNotEmpty($now);
    }

    public function test_all_phase1_tables_exist_in_postgresql(): void
    {
        $requiredTables = [
            'users',
            'students',
            'teachers',
            'field_supervisors',
            'locations',
            'location_field_supervisor',
            'periods',
            'placements',
            'attendances',
            'activities',
            'logbooks',
            'logbook_comments',
            'leave_requests',
        ];

        foreach ($requiredTables as $table) {
            $this->assertTrue(
                Schema::connection('pgsql')->hasTable($table),
                "Table [{$table}] must exist in PostgreSQL database."
            );
        }
    }

    public function test_location_default_radius_in_postgresql_is_50m(): void
    {
        $locationId = DB::connection('pgsql')->table('locations')->insertGetId([
            'name' => 'Postgres Test Workshop ' . uniqid(),
            'address' => 'Jl. Uji Coba No. 12',
            'latitude' => -6.2000000,
            'longitude' => 106.8000000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $location = DB::connection('pgsql')->table('locations')->where('id', $locationId)->first();
        $this->assertEquals(50, $location->radius);
        $this->assertTrue((bool) $location->is_active);

        DB::connection('pgsql')->table('locations')->where('id', $locationId)->delete();
    }

    public function test_postgresql_location_and_supervisor_many_to_many_relation(): void
    {
        // Create user & supervisor
        $userId = DB::connection('pgsql')->table('users')->insertGetId([
            'name' => 'PG Supervisor ' . uniqid(),
            'username' => 'pg.spv.' . uniqid(),
            'password' => bcrypt('secret'),
            'role' => 'field_supervisor',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supervisorId = DB::connection('pgsql')->table('field_supervisors')->insertGetId([
            'user_id' => $userId,
            'name' => 'Supervisor PG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $locationId = DB::connection('pgsql')->table('locations')->insertGetId([
            'name' => 'PG Location ' . uniqid(),
            'address' => 'Jl. PG No. 1',
            'latitude' => -6.2100000,
            'longitude' => 106.8100000,
            'radius' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Link via pivot
        DB::connection('pgsql')->table('location_field_supervisor')->insert([
            'location_id' => $locationId,
            'field_supervisor_id' => $supervisorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pivotRecord = DB::connection('pgsql')->table('location_field_supervisor')
            ->where('location_id', $locationId)
            ->where('field_supervisor_id', $supervisorId)
            ->first();

        $this->assertNotNull($pivotRecord);

        // Clean up
        DB::connection('pgsql')->table('location_field_supervisor')->where('location_id', $locationId)->delete();
        DB::connection('pgsql')->table('locations')->where('id', $locationId)->delete();
        DB::connection('pgsql')->table('field_supervisors')->where('id', $supervisorId)->delete();
        DB::connection('pgsql')->table('users')->where('id', $userId)->delete();
    }

    public function test_case_insensitive_search_macro_works_on_postgresql(): void
    {
        $suffix = uniqid();
        $locationId = DB::connection('pgsql')->table('locations')->insertGetId([
            'name' => "PG CaseSearch Bengkel {$suffix}",
            'address' => "Jl. Raya CaseSearch {$suffix}",
            'latitude' => -6.2000000,
            'longitude' => 106.8000000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $foundByName = \App\Models\Location::on('pgsql')
                ->where(function ($q) use ($suffix) {
                    $q->whereLikeInsensitive('name', "%pg casesearch bengkel {$suffix}%")
                      ->orWhereLikeInsensitive('address', '%TIDAKADA%');
                })
                ->exists();

            $foundByAddress = \App\Models\Location::on('pgsql')
                ->where(function ($q) use ($suffix) {
                    $q->whereLikeInsensitive('name', '%TIDAKADA%')
                      ->orWhereLikeInsensitive('address', "%jl. raya casesearch {$suffix}%");
                })
                ->exists();

            $this->assertTrue($foundByName);
            $this->assertTrue($foundByAddress);
        } finally {
            DB::connection('pgsql')->table('locations')->where('id', $locationId)->delete();
        }
    }
}
