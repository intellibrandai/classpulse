<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SemesterPeriodRequest;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\ReportData;
use App\Support\SchoolCalendar;
use App\Support\SemesterView;
use Illuminate\Http\JsonResponse;

class StudentSemesterController extends Controller
{
    /**
     * The Semester Analytics inspector payload of one student (stats, weekly chart, audit log, notes) for a period.
     * The student is resolved inside the class in the URL (scoped bindings), so another class's student is a 404.
     */
    public function show(SemesterPeriodRequest $request, SchoolClass $class, Student $student, ReportData $reports, SchoolCalendar $calendar): JsonResponse
    {
        $data = $reports->semester($class, $request->periodKey());
        $day = $reports->studentDay($class, $student, $calendar->defaultDate());

        return response()->json(['data' => SemesterView::student($data, [
            'id' => $student->id,
            'name' => $student->display_name,
            'preferred_name' => $student->preferred_name,
            'student_number' => $student->student_number,
            'archived' => $student->isArchived(),
        ], $day, $calendar->today())]);
    }
}
