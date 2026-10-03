<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Services\ReportData;
use App\Support\SchoolCalendar;
use App\Support\WeeklyView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class WeekController extends Controller
{
    /**
     * The recomputed Weekly Matrix numbers (WeeklyView::build) for one ISO week of one class.
     */
    public function show(SchoolClass $class, string $monday, SchoolCalendar $calendar, ReportData $reports): JsonResponse
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $monday, 'UTC');
        if ($parsed === false || $parsed->format('Y-m-d') !== $monday) {
            return response()->json(['error' => ['code' => 'validation', 'message' => 'The date is not a valid calendar date.']], 422);
        }
        if ($parsed->dayOfWeekIso !== 1) {
            return response()->json(['error' => ['code' => 'validation', 'message' => 'The week must start on a Monday.']], 422);
        }

        return response()->json(['data' => WeeklyView::build($reports->weekly($class, $monday), $calendar->today(), $class->name)]);
    }
}
