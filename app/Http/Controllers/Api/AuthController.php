<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    /**
     * Login user using username, student NIS, or teacher code, and create token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = $request->getIdentifier();
        $user = $this->resolveUserByIdentifier($identifier);

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->error('Username, NIS, atau password salah.', 401);
        }

        if (!$user->is_active) {
            return $this->error('Akun Anda tidak aktif. Hubungi administrator.', 403);
        }

        // Role is strictly determined from database record
        match ($user->role) {
            'student' => $user->load('student'),
            'teacher' => $user->load('teacher'),
            'field_supervisor' => $user->load('fieldSupervisor'),
            default => null,
        };

        $token = $user->createToken('auth-token')->plainTextToken;

        return $this->success([
            'user' => new UserResource($user),
            'token' => $token,
        ], 'Login berhasil.');
    }

    /**
     * Unambiguously resolve identifier to User account (username, email, student NIS, teacher code).
     */
    protected function resolveUserByIdentifier(string $identifier): ?User
    {
        // 1. Direct username or email match
        $user = User::where('username', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        if ($user) {
            return $user;
        }

        // 2. Student NIS match
        $student = Student::with('user')->where('nis', $identifier)->first();
        if ($student && $student->user) {
            return $student->user;
        }

        // 3. Teacher code (Kode Guru) match
        $teacher = Teacher::with('user')->where('teacher_code', $identifier)->first();
        if ($teacher && $teacher->user) {
            return $teacher->user;
        }

        return null;
    }

    /**
     * Logout and revoke token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Logout berhasil.');
    }

    /**
     * Get authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        // Load profile based on role
        match ($user->role) {
            'student' => $user->load('student'),
            'teacher' => $user->load('teacher'),
            'field_supervisor' => $user->load('fieldSupervisor'),
            default => null,
        };

        return $this->success(new UserResource($user));
    }
}
