<?php

namespace Tests\Feature;

use App\Models\FieldSupervisor;
use App\Models\Location;
use App\Models\Period;
use App\Models\Placement;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\CreatesTestData;
use Tests\TestCase;

class Phase1AuthAndMasterDataTest extends TestCase
{
    use CreatesTestData;

    // ── Multi-Identifier Authentication ──────────

    public function test_student_can_login_with_nis(): void
    {
        [$user, $student] = $this->createStudentUser();

        $response = $this->postJson('/api/login', [
            'identifier' => $student->nis,
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'student')
            ->assertJsonPath('data.user.username', $user->username);
    }

    public function test_teacher_can_login_with_teacher_code(): void
    {
        [$user, $teacher] = $this->createTeacherUser();

        $response = $this->postJson('/api/login', [
            'identifier' => $teacher->teacher_code,
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'teacher')
            ->assertJsonPath('data.user.username', $user->username);
    }

    // ── Admin Security & User Management ─────────

    public function test_raw_user_cannot_be_created_directly_via_admin_users_endpoint(): void
    {
        $admin = $this->createAdmin();
        $token = $admin->createToken('test')->plainTextToken;

        // Raw POST to /api/admin/users is disabled; domain records must use /students, /teachers, /field-supervisors
        $response = $this->withToken($token)->postJson('/api/admin/users', [
            'name' => 'Attempted Rogue Admin',
            'username' => 'rogue.admin.' . uniqid(),
            'email' => 'rogue.' . uniqid() . '@test.com',
            'password' => 'password123',
            'role' => 'admin',
        ]);

        $response->assertStatus(405);
    }

    public function test_admin_can_update_user_and_sync_profile_name(): void
    {
        $admin = $this->createAdmin();
        $token = $admin->createToken('test')->plainTextToken;

        [$studentUser, $student] = $this->createStudentUser();

        $response = $this->withToken($token)->putJson("/api/admin/users/{$studentUser->id}", [
            'name' => 'Nama Siswa Terupdate',
            'email' => 'updated.student@test.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Nama Siswa Terupdate');

        $this->assertEquals('Nama Siswa Terupdate', $student->fresh()->name);
        $this->assertEquals('updated.student@test.com', $studentUser->fresh()->email);
    }

    public function test_admin_can_view_single_user(): void
    {
        $admin = $this->createAdmin();
        $token = $admin->createToken('test')->plainTextToken;

        [$studentUser] = $this->createStudentUser();

        $response = $this->withToken($token)->getJson("/api/admin/users/{$studentUser->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $studentUser->id)
            ->assertJsonPath('data.username', $studentUser->username);
    }

    public function test_admin_can_reset_user_password_and_revoke_tokens(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $admin->createToken('admin-token')->plainTextToken;

        [$studentUser] = $this->createStudentUser();
        $oldStudentToken = $studentUser->createToken('student-old-session')->plainTextToken;

        $response = $this->withToken($adminToken)->postJson("/api/admin/users/{$studentUser->id}/reset-password", [
            'password' => 'newSecretPassword123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // Old token must be invalidated
        $this->app['auth']->forgetGuards();
        $checkOldToken = $this->withToken($oldStudentToken)->getJson('/api/me');
        $checkOldToken->assertStatus(401);

        // User can login with new password
        $loginNew = $this->postJson('/api/login', [
            'username' => $studentUser->username,
            'password' => 'newSecretPassword123',
        ]);
        $loginNew->assertStatus(200);
    }

    public function test_non_admin_cannot_reset_passwords(): void
    {
        [$studentUser] = $this->createStudentUser();
        $token = $studentUser->createToken('test')->plainTextToken;

        [$otherUser] = $this->createTeacherUser();

        $response = $this->withToken($token)->postJson("/api/admin/users/{$otherUser->id}/reset-password", [
            'password' => 'newPassword123',
        ]);

        $response->assertStatus(403);
    }

    // ── Safe Cascade Deletion ──────────────────────

    public function test_deleting_student_removes_account_and_placements_cleanly(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $admin->createToken('admin')->plainTextToken;

        [$studentUser, $student] = $this->createStudentUser();
        $location = $this->createLocation();
        $period = $this->createActivePeriod();
        [$teacherUser, $teacher] = $this->createTeacherUser();
        $placement = $this->createPlacement($student, $location, $period, $teacher);

        $response = $this->withToken($adminToken)->deleteJson("/api/admin/students/{$student->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('students', ['id' => $student->id]);
        $this->assertDatabaseMissing('placements', ['id' => $placement->id]);
        $this->assertDatabaseMissing('users', ['id' => $studentUser->id]);
    }

    public function test_deleting_location_with_placements_removes_them_cleanly(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $admin->createToken('admin')->plainTextToken;

        [$studentUser, $student] = $this->createStudentUser();
        $location = $this->createLocation();
        $period = $this->createActivePeriod();
        [$teacherUser, $teacher] = $this->createTeacherUser();
        $placement = $this->createPlacement($student, $location, $period, $teacher);

        $response = $this->withToken($adminToken)->deleteJson("/api/admin/locations/{$location->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
        $this->assertDatabaseMissing('placements', ['id' => $placement->id]);
    }

    public function test_admin_search_endpoints_are_case_insensitive(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $admin->createToken('admin')->plainTextToken;

        [$studentUser, $student] = $this->createStudentUser();
        $student->update(['name' => 'Zidan Nusantara']);

        [$teacherUser, $teacher] = $this->createTeacherUser();
        $teacher->update(['name' => 'Pak Surya Pembimbing']);

        [$supervisorUser, $supervisor] = $this->createSupervisorUser();
        $supervisor->update(['name' => 'Ibu Ratna DUDI', 'organization' => 'PT Maju Bersama']);

        $location = $this->createLocation(['name' => 'Bengkel Garuda Jaya', 'address' => 'Jl. Merdeka Selatan']);
        $period = Period::create([
            'name' => 'Semester Gasal 2026/2027',
            'start_date' => now()->subDays(5),
            'end_date' => now()->addDays(60),
            'status' => 'active',
        ]);

        $this->withToken($adminToken)->getJson('/api/admin/students?search=zidan')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Zidan Nusantara']);

        $this->withToken($adminToken)->getJson('/api/admin/teachers?search=SURYA')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Pak Surya Pembimbing']);

        $this->withToken($adminToken)->getJson('/api/admin/field-supervisors?search=maju%20bersama')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Ibu Ratna DUDI']);

        $this->withToken($adminToken)->getJson('/api/admin/locations?search=merdeka')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Bengkel Garuda Jaya']);

        $this->withToken($adminToken)->getJson('/api/admin/periods?search=gasal')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Semester Gasal 2026/2027']);

        $this->withToken($adminToken)->getJson('/api/admin/users?search=' . strtoupper($studentUser->username))
            ->assertStatus(200)
            ->assertJsonFragment(['username' => $studentUser->username]);
    }

    // ── Placement Conflict Prevention ────────────

    public function test_prevent_conflicting_active_placements_in_same_period(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $admin->createToken('admin')->plainTextToken;

        [$studentUser, $student] = $this->createStudentUser();
        $location1 = $this->createLocation(['name' => 'Loc 1']);
        $location2 = $this->createLocation(['name' => 'Loc 2']);
        $period = $this->createActivePeriod();
        [$teacherUser, $teacher] = $this->createTeacherUser();

        // First placement
        $this->createPlacement($student, $location1, $period, $teacher);

        // Attempt second active placement for same student in same period
        $response = $this->withToken($adminToken)->postJson('/api/admin/placements', [
            'student_id' => $student->id,
            'location_id' => $location2->id,
            'period_id' => $period->id,
            'teacher_id' => $teacher->id,
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
            'status' => 'active',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);
    }

    public function test_allow_multiple_placements_in_different_periods(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $admin->createToken('admin')->plainTextToken;

        [$studentUser, $student] = $this->createStudentUser();
        $location = $this->createLocation();
        $period1 = $this->createActivePeriod();
        $period2 = Period::create([
            'name' => 'Next Period',
            'start_date' => now()->addMonths(6),
            'end_date' => now()->addMonths(9),
            'status' => 'inactive',
        ]);
        [$teacherUser, $teacher] = $this->createTeacherUser();

        // Placement in period 1
        $this->createPlacement($student, $location, $period1, $teacher);

        // Placement in period 2
        $response = $this->withToken($adminToken)->postJson('/api/admin/placements', [
            'student_id' => $student->id,
            'location_id' => $location->id,
            'period_id' => $period2->id,
            'teacher_id' => $teacher->id,
            'start_date' => $period2->start_date->toDateString(),
            'end_date' => $period2->end_date->toDateString(),
            'status' => 'active',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    // ── Field Supervisor Location Management ──────

    public function test_supervisor_can_register_and_manage_locations(): void
    {
        [$supervisorUser, $supervisor] = $this->createSupervisorUser();
        $token = $supervisorUser->createToken('supervisor')->plainTextToken;

        // Register new location
        $createResponse = $this->withToken($token)->postJson('/api/supervisor/locations', [
            'name' => 'Bengkel Mobil Maju',
            'address' => 'Jl. Industri No. 10',
            'latitude' => -6.211234,
            'longitude' => 106.851234,
            'radius' => 50,
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.radius', 50);

        $locationId = $createResponse->json('data.id');

        // Supervisor can list their locations
        $listResponse = $this->withToken($token)->getJson('/api/supervisor/locations');
        $listResponse->assertStatus(200)
            ->assertJsonPath('success', true);
        $this->assertNotEmpty($listResponse->json('data.locations'));

        // Supervisor can update their location
        $updateResponse = $this->withToken($token)->putJson("/api/supervisor/locations/{$locationId}", [
            'name' => 'Bengkel Mobil Maju Sejahtera',
        ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.name', 'Bengkel Mobil Maju Sejahtera');
    }

    public function test_unauthenticated_non_json_api_request_returns_401_instead_of_route_login_error(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'text/html',
        ])->get('/api/me');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated. Silakan login terlebih dahulu.');
    }

    public function test_public_storage_fallback_route_serves_uploaded_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('attendances/check-in/2026-11-16/sample.txt', 'ok');

        $this->get('/storage/attendances/check-in/2026-11-16/sample.txt')
            ->assertStatus(200);

        $this->get('/storage/attendances/check-in/2026-11-16/missing.txt')
            ->assertStatus(404);
    }
}
