<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentNoteRequest;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentNote;
use Illuminate\Http\JsonResponse;

class StudentNoteController extends Controller
{
    public function index(SchoolClass $class, Student $student): JsonResponse
    {
        $notes = StudentNote::query()
            ->where('school_class_id', $class->id)
            ->where('student_id', $student->id)
            ->orderByDesc('note_date')
            ->get();

        return response()->json(['data' => ['notes' => $notes->map(fn (StudentNote $n) => self::present($n))->all()]]);
    }

    public function upsert(StudentNoteRequest $request, SchoolClass $class, Student $student, string $date): JsonResponse
    {
        $body = (string) ($request->validated('body') ?? '');
        $scope = ['school_class_id' => $class->id, 'student_id' => $student->id, 'note_date' => $date];

        if ($body === '') {
            $deleted = StudentNote::query()->where($scope)->delete() > 0;

            return response()->json(['data' => ['note' => null, 'deleted' => $deleted]]);
        }

        $note = StudentNote::query()->where($scope)->first() ?? new StudentNote($scope);
        $note->body = $body;
        $note->save();

        return response()->json(['data' => ['note' => self::present($note), 'deleted' => false]]);
    }

    public function destroy(StudentNoteRequest $request, SchoolClass $class, Student $student, string $date): JsonResponse
    {
        $deleted = StudentNote::query()
            ->where(['school_class_id' => $class->id, 'student_id' => $student->id, 'note_date' => $date])
            ->delete() > 0;

        return response()->json(['data' => ['note' => null, 'deleted' => $deleted]]);
    }

    /**
     * @return array{date: string, body: string, updated_at: string}
     */
    private static function present(StudentNote $note): array
    {
        return [
            'date' => $note->note_date->format('Y-m-d'),
            'body' => $note->body,
            'updated_at' => $note->updated_at->toIso8601String(),
        ];
    }
}
