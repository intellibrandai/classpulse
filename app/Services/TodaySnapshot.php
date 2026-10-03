<?php

namespace App\Services;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Support\SchoolCalendar;

/**
 * Header chips: Enrolled / Present / Absent for the current class on today's school date
 * (today, or the previous Friday on a weekend). Read-only.
 */
class TodaySnapshot
{
    public function __construct(private readonly SchoolCalendar $calendar) {}

    /**
     * @return array{date: string, enrolled: int, present: int, absent: int, not_recorded: int}
     */
    public function for(?SchoolClass $class): array
    {
        $date = $this->calendar->defaultDate();
        if ($class === null) {
            return ['date' => $date, 'enrolled' => 0, 'present' => 0, 'absent' => 0, 'not_recorded' => 0];
        }

        $enrolled = $class->activeStudentCount();
        $counts = ParticipationEntry::query()->toBase()
            ->where('school_class_id', $class->id)
            ->where('work_date', $date)
            ->whereIn('student_id', $class->activeStudents()->select('id'))
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status');
        $present = (int) ($counts['present'] ?? 0);
        $absent = (int) ($counts['absent'] ?? 0);

        return [
            'date' => $date,
            'enrolled' => $enrolled,
            'present' => $present,
            'absent' => $absent,
            'not_recorded' => max(0, $enrolled - $present - $absent),
        ];
    }
}
