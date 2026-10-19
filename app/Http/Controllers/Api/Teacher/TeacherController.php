<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\JournalResource;
use App\Http\Resources\StudentResource;
use App\Models\Attendance;
use App\Models\Journal;
use App\Models\Placement;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherController extends Controller
{
    use ApiResponse;

    /**
     * Get placement IDs for the authenticated teacher.
     */
    private function getTeacherPlacementIds(Request $request): array
    {
        $teacher = $request->user()->teacher;
        return $teacher->placements()->pluck('id')->toArray();
    }

    /**
     * List students under this teacher's supervision.
     */
    public function students(Request $request): JsonResponse
    {
        $teacher = $request->user()->teacher;
        $placements = Placement::with(['student', 'location', 'period'])
            ->where('teacher_id', $teacher->id)
            ->where('status', 'active')
            ->get();

        $students = $placements->map(function ($placement) {
            $student = $placement->student;
            $student->setAttribute('placement', $placement);
            return $student;
        })->unique('id');

        return $this->success([
            'students' => StudentResource::collection($students),
        ]);
    }

    /**
     * Show specific student detail.
     */
    public function showStudent(Request $request, int $id): JsonResponse
    {
        $teacher = $request->user()->teacher;
        $placement = Placement::with(['student', 'location', 'period'])
            ->where('teacher_id', $teacher->id)
            ->where('student_id', $id)
            ->firstOrFail();

        return $this->success(new StudentResource($placement->student->load('user')));
    }

    /**
     * List attendances of students under this teacher.
     */
    public function attendances(Request $request): JsonResponse
    {
        $placementIds = $this->getTeacherPlacementIds($request);

        $query = Attendance::with('placement.student')
            ->whereIn('placement_id', $placementIds);

        if ($request->has('date')) {
            $query->where('attendance_date', $request->date);
        }
        if ($request->has('student_id')) {
            $query->whereHas('placement', fn($q) => $q->where('student_id', $request->student_id));
        }

        $attendances = $query->latest('attendance_date')
            ->paginate($request->get('per_page', 15));

        return $this->success([
            'attendances' => AttendanceResource::collection($attendances),
            'pagination' => [
                'current_page' => $attendances->currentPage(),
                'last_page' => $attendances->lastPage(),
                'per_page' => $attendances->perPage(),
                'total' => $attendances->total(),
            ],
        ]);
    }

    /**
     * List journals of students under this teacher.
     */
    public function journals(Request $request): JsonResponse
    {
        $placementIds = $this->getTeacherPlacementIds($request);

        $query = Journal::with('placement.student')
            ->whereIn('placement_id', $placementIds);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('student_id')) {
            $query->whereHas('placement', fn($q) => $q->where('student_id', $request->student_id));
        }

        $journals = $query->latest('logbook_date')
            ->paginate($request->get('per_page', 15));

        return $this->success([
            'journals' => JournalResource::collection($journals),
            'pagination' => [
                'current_page' => $journals->currentPage(),
                'last_page' => $journals->lastPage(),
                'per_page' => $journals->perPage(),
                'total' => $journals->total(),
            ],
        ]);
    }

    /**
     * Teacher dashboard statistics.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $placementIds = $this->getTeacherPlacementIds($request);
        $today = now()->toDateString();

        return $this->success([
            'total_students' => Placement::whereIn('id', $placementIds)->where('status', 'active')->count(),
            'attendance_today' => Attendance::whereIn('placement_id', $placementIds)
                ->where('attendance_date', $today)->count(),
            'journals_pending' => Journal::whereIn('placement_id', $placementIds)
                ->where('status', 'submitted')->count(),
        ]);
    }
}
