<?php

use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\FieldSupervisorController as AdminFieldSupervisorController;
use App\Http\Controllers\Api\Admin\LocationController as AdminLocationController;
use App\Http\Controllers\Api\Admin\PeriodController as AdminPeriodController;
use App\Http\Controllers\Api\Admin\PlacementController as AdminPlacementController;
use App\Http\Controllers\Api\Admin\StudentController as AdminStudentController;
use App\Http\Controllers\Api\Admin\TeacherController as AdminTeacherController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Student\StudentController;
use App\Http\Controllers\Api\Supervisor\SupervisorController;
use App\Http\Controllers\Api\Teacher\TeacherController;
use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────
// Public routes (no auth required)
// Supports both /api/login and /api/auth/login
// ─────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// ─────────────────────────────────────────────
// Authenticated routes
// ─────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth endpoints (both /api/* and /api/auth/* formats)
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // ── Admin routes ──────────────────────────
    Route::prefix('admin')->middleware('role:admin')->group(function () {

        Route::get('/dashboard', [AdminDashboardController::class, 'index']);

        // Users CRUD & Admin Password Reset
        Route::post('/users/{id}/reset-password', [AdminUserController::class, 'resetPassword']);
        Route::apiResource('users', AdminUserController::class);

        // Students CRUD
        Route::apiResource('students', AdminStudentController::class);

        // Teachers CRUD
        Route::apiResource('teachers', AdminTeacherController::class);

        // Field Supervisors CRUD
        Route::apiResource('field-supervisors', AdminFieldSupervisorController::class);

        // Locations CRUD
        Route::apiResource('locations', AdminLocationController::class);

        // Periods CRUD
        Route::apiResource('periods', AdminPeriodController::class);

        // Placements CRUD
        Route::apiResource('placements', AdminPlacementController::class);
    });

    // ── Student routes ────────────────────────
    Route::prefix('student')->middleware('role:student')->group(function () {

        Route::get('/dashboard', [StudentController::class, 'dashboard']);

        // Profile
        Route::get('/profile', [StudentController::class, 'profile']);
        Route::put('/profile', [StudentController::class, 'updateProfile']);

        // Active placement
        Route::get('/placement', [StudentController::class, 'placement']);

        // Attendance
        Route::get('/attendances', [StudentController::class, 'attendances']);
        Route::post('/attendances/check-in', [StudentController::class, 'checkIn']);
        Route::post('/attendances/check-out', [StudentController::class, 'checkOut']);
        Route::get('/attendances/{id}', [StudentController::class, 'showAttendance']);

        // Logbooks (with backward-compatible /journals aliases)
        foreach (['logbooks', 'journals'] as $logbookPrefix) {
            Route::get("/{$logbookPrefix}", [StudentController::class, 'journals']);
            Route::post("/{$logbookPrefix}", [StudentController::class, 'storeJournal']);
            Route::get("/{$logbookPrefix}/{id}", [StudentController::class, 'showJournal']);
            Route::put("/{$logbookPrefix}/{id}", [StudentController::class, 'updateJournal']);
            Route::delete("/{$logbookPrefix}/{id}", [StudentController::class, 'destroyJournal']);
            Route::post("/{$logbookPrefix}/{id}/submit", [StudentController::class, 'submitJournal']);
        }
    });

    // ── Teacher routes ────────────────────────
    Route::prefix('teacher')->middleware('role:teacher')->group(function () {

        Route::get('/dashboard', [TeacherController::class, 'dashboard']);
        Route::get('/students', [TeacherController::class, 'students']);
        Route::get('/students/{id}', [TeacherController::class, 'showStudent']);
        Route::get('/attendances', [TeacherController::class, 'attendances']);

        // Logbooks (with backward-compatible /journals alias)
        Route::get('/logbooks', [TeacherController::class, 'journals']);
        Route::get('/journals', [TeacherController::class, 'journals']);
    });

    // ── Field Supervisor routes (supports both /supervisor and /field-supervisor) ───
    foreach (['supervisor', 'field-supervisor'] as $spvPrefix) {
        Route::prefix($spvPrefix)->middleware('role:field_supervisor')->group(function () {

            Route::get('/dashboard', [SupervisorController::class, 'dashboard']);
            Route::get('/students', [SupervisorController::class, 'students']);
            Route::get('/students/{id}', [SupervisorController::class, 'showStudent']);

            // Field Supervisor Location Management
            Route::get('/locations', [SupervisorController::class, 'locations']);
            Route::post('/locations', [SupervisorController::class, 'storeLocation']);
            Route::get('/locations/{id}', [SupervisorController::class, 'showLocation']);
            Route::put('/locations/{id}', [SupervisorController::class, 'updateLocation']);

            // Logbooks (with backward-compatible /journals alias)
            foreach (['logbooks', 'journals'] as $logbookPrefix) {
                Route::get("/{$logbookPrefix}", [SupervisorController::class, 'journals']);
                Route::get("/{$logbookPrefix}/{id}", [SupervisorController::class, 'showJournal']);
                Route::post("/{$logbookPrefix}/{id}/approve", [SupervisorController::class, 'approveJournal']);
                Route::post("/{$logbookPrefix}/{id}/reject", [SupervisorController::class, 'rejectJournal']);
            }

            Route::get('/attendances', [SupervisorController::class, 'attendances']);
        });
    }
});
