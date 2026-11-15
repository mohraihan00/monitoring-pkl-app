<?php

namespace Tests\Feature;

use App\Models\Location;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MysqlIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Target isolated MySQL test database regardless of active .env driver
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.host', env('DB_MYSQL_TEST_HOST', '127.0.0.1'));
        Config::set('database.connections.mysql.port', env('DB_MYSQL_TEST_PORT', '3306'));
        Config::set('database.connections.mysql.database', env('DB_MYSQL_TEST_DATABASE', 'monitoring_pkl_test'));
        Config::set('database.connections.mysql.username', env('DB_MYSQL_TEST_USERNAME', 'root'));
        Config::set('database.connections.mysql.password', env('DB_MYSQL_TEST_PASSWORD', ''));
        Config::set('database.connections.mysql.charset', 'utf8mb4');
        Config::set('database.connections.mysql.collation', 'utf8mb4_unicode_ci');
        Config::set('database.connections.mysql.timezone', '+07:00');

        DB::purge('mysql');

        try {
            DB::connection('mysql')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL server not available: ' . $e->getMessage());
        }
    }

    public function test_mysql_connection_and_timezone_are_active(): void
    {
        $version = DB::connection('mysql')->select('SELECT VERSION() as v')[0]->v;
        $this->assertNotEmpty($version);

        $now = DB::connection('mysql')->select('SELECT CURRENT_TIME() as t');
        $this->assertNotEmpty($now);
    }

    public function test_all_schema_tables_exist_in_mysql(): void
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
            'logbook_status_histories',
            'leave_requests',
            'project_submissions',
            'project_reviews',
            'assessments',
        ];

        foreach ($requiredTables as $table) {
            $this->assertTrue(
                Schema::connection('mysql')->hasTable($table),
                "Table [{$table}] must exist in MySQL database."
            );
        }
    }

    public function test_location_default_radius_and_work_hours_in_mysql(): void
    {
        $locationId = DB::connection('mysql')->table('locations')->insertGetId([
            'name' => 'MySQL Test Workshop ' . uniqid(),
            'address' => 'Jl. Uji Coba MySQL No. 12',
            'latitude' => -6.2000000,
            'longitude' => 106.8000000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $location = DB::connection('mysql')->table('locations')->where('id', $locationId)->first();
            $this->assertEquals(50, $location->radius);
            $this->assertTrue((bool) $location->is_active);
            $this->assertEquals('approved', $location->approval_status);
            $this->assertEquals('08:00:00', $location->check_in_time);
        } finally {
            DB::connection('mysql')->table('locations')->where('id', $locationId)->delete();
        }
    }

    public function test_mysql_location_and_supervisor_many_to_many_relation(): void
    {
        $userId = DB::connection('mysql')->table('users')->insertGetId([
            'name' => 'MySQL Supervisor ' . uniqid(),
            'username' => 'mysql.spv.' . uniqid(),
            'password' => bcrypt('secret'),
            'role' => 'field_supervisor',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supervisorId = DB::connection('mysql')->table('field_supervisors')->insertGetId([
            'user_id' => $userId,
            'name' => 'Supervisor MySQL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $locationId = DB::connection('mysql')->table('locations')->insertGetId([
            'name' => 'MySQL Location ' . uniqid(),
            'address' => 'Jl. MySQL No. 1',
            'latitude' => -6.2100000,
            'longitude' => 106.8100000,
            'radius' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::connection('mysql')->table('location_field_supervisor')->insert([
                'location_id' => $locationId,
                'field_supervisor_id' => $supervisorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $pivotRecord = DB::connection('mysql')->table('location_field_supervisor')
                ->where('location_id', $locationId)
                ->where('field_supervisor_id', $supervisorId)
                ->first();

            $this->assertNotNull($pivotRecord);
        } finally {
            DB::connection('mysql')->table('location_field_supervisor')->where('location_id', $locationId)->delete();
            DB::connection('mysql')->table('locations')->where('id', $locationId)->delete();
            DB::connection('mysql')->table('field_supervisors')->where('id', $supervisorId)->delete();
            DB::connection('mysql')->table('users')->where('id', $userId)->delete();
        }
    }

    public function test_case_insensitive_search_macro_works_on_mysql(): void
    {
        $suffix = uniqid();
        $locationId = DB::connection('mysql')->table('locations')->insertGetId([
            'name' => "MySQL CaseSearch Bengkel {$suffix}",
            'address' => "Jl. Raya MySQL CaseSearch {$suffix}",
            'latitude' => -6.2000000,
            'longitude' => 106.8000000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $foundByName = Location::on('mysql')
                ->where(function ($q) use ($suffix) {
                    $q->whereLikeInsensitive('name', "%mysql casesearch bengkel {$suffix}%")
                      ->orWhereLikeInsensitive('address', '%TIDAKADA%');
                })
                ->exists();

            $foundByAddress = Location::on('mysql')
                ->where(function ($q) use ($suffix) {
                    $q->whereLikeInsensitive('name', '%TIDAKADA%')
                      ->orWhereLikeInsensitive('address', "%JL. RAYA MYSQL CASESEARCH {$suffix}%");
                })
                ->exists();

            $this->assertTrue($foundByName);
            $this->assertTrue($foundByAddress);
        } finally {
            DB::connection('mysql')->table('locations')->where('id', $locationId)->delete();
        }
    }
}
