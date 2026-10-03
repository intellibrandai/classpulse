<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportCommentRequest;
use App\Models\ReportCommentDraft;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\JsonResponse;

class ReportCommentController extends Controller
{
    public function index(ReportCommentRequest $request, SchoolClass $class): JsonResponse
    {
        $period = $request->period();
        $drafts = ReportCommentDraft::query()
            ->where('school_class_id', $class->id)
            ->where('period', $period)
            ->get()
            ->mapWithKeys(fn (ReportCommentDraft $d) => [(string) $d->student_id => self::present($d)])
            ->all();

        return response()->json(['data' => ['period' => $period, 'drafts' => (object) $drafts]]);
    }

    public function upsert(ReportCommentRequest $request, SchoolClass $class, Student $student): JsonResponse
    {
        $period = $request->period();
        $body = (string) ($request->validated('body') ?? '');
        $scope = ['school_class_id' => $class->id, 'student_id' => $student->id, 'period' => $period];

        if ($body === '') {
            $deleted = ReportCommentDraft::query()->where($scope)->delete() > 0;

            return response()->json(['data' => ['draft' => null, 'deleted' => $deleted]]);
        }

        $draft = ReportCommentDraft::query()->where($scope)->first() ?? new ReportCommentDraft($scope);
        $draft->body = $body;
        $draft->save();

        return response()->json(['data' => ['draft' => self::present($draft), 'deleted' => false]]);
    }

    public function destroy(ReportCommentRequest $request, SchoolClass $class, Student $student): JsonResponse
    {
        $deleted = ReportCommentDraft::query()
            ->where(['school_class_id' => $class->id, 'student_id' => $student->id, 'period' => $request->period()])
            ->delete() > 0;

        return response()->json(['data' => ['draft' => null, 'deleted' => $deleted]]);
    }

    /**
     * @return array{body: string, updated_at: string}
     */
    private static function present(ReportCommentDraft $draft): array
    {
        return ['body' => $draft->body, 'updated_at' => $draft->updated_at->toIso8601String()];
    }
}
