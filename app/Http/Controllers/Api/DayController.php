<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ParticipationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DayOperationRequest;
use App\Models\SchoolClass;
use App\Services\ParticipationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class DayController extends Controller
{
    /** Business codes whose error body carries the current day state. */
    private const CODES_WITH_DAY = [
        'points_max', 'points_min', 'not_recorded', 'student_archived', 'nothing_to_do',
        'student_absent', 'unchanged', 'already_recorded', 'already_absent', 'not_absent', 'nothing_to_undo',
    ];

    public function __construct(private readonly ParticipationService $service) {}

    public function show(SchoolClass $class, string $date): JsonResponse
    {
        if ($invalid = $this->invalidDate($date)) {
            return $invalid;
        }

        return response()->json(['data' => $this->service->dayState($class, $date)]);
    }

    public function store(DayOperationRequest $request, SchoolClass $class, string $date): JsonResponse
    {
        if ($invalid = $this->invalidDate($date)) {
            return $invalid;
        }

        try {
            $day = $this->service->apply(
                $class,
                $date,
                $request->string('op_id')->toString(),
                $request->string('kind')->toString(),
                $request->filled('student_id') ? $request->integer('student_id') : null,
                $request->filled('points') ? $request->integer('points') : null,
            );
        } catch (ParticipationException $e) {
            $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            if (in_array($e->errorCode, self::CODES_WITH_DAY, true)) {
                $error['day'] = $this->service->dayState($class, $date);
            }

            return response()->json(['error' => $error], $e->status);
        }

        return response()->json(['data' => $day]);
    }

    private function invalidDate(string $date): ?JsonResponse
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return response()->json(['error' => ['code' => 'validation', 'message' => 'The date is not a valid calendar date.']], 422);
        }

        return null;
    }
}
