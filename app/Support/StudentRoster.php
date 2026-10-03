<?php

namespace App\Support;

/**
 * Per-student figures for the Class Roster screen: semester average per present day and today's status. Pure.
 *
 * Search and sorting are left to the UI.
 */
final class StudentRoster
{
    public function __construct(private readonly ParticipationStats $stats = new ParticipationStats) {}

    /**
     * @param  array<int, int>  $studentIds
     * @param  array<int, array{student_id: int, work_date: string, status: string, points: ?int}>  $rows  must include the school date's rows; the average uses $from..$to only
     * @param  string  $schoolDate  'Y-m-d' whose status is shown (SchoolCalendar::defaultDate())
     * @return array<int, array{student_id: int, average: ?float, present_days: int, absences: int, today: 'present'|'absent'|'none', today_points: ?int}>
     *                                                                                                                                                     keyed by student id; average null = "No data"
     */
    public function build(array $studentIds, array $rows, string $schoolDate, ?string $from = null, ?string $to = null): array
    {
        $byStudent = [];
        $today = [];
        foreach ($rows as $row) {
            $byStudent[$row['student_id']][] = $row;
            if ($row['work_date'] === $schoolDate) {
                $today[$row['student_id']] = $row;
            }
        }

        $out = [];
        foreach ($studentIds as $id) {
            $s = $this->stats->summarize($byStudent[$id] ?? [], $from, $to);
            $t = $today[$id] ?? null;
            $out[$id] = [
                'student_id' => $id,
                'average' => $s['average'],
                'present_days' => $s['present_days_recorded'],
                'absences' => $s['absences'],
                'today' => $t === null ? 'none' : $t['status'],
                'today_points' => $t !== null && $t['status'] === 'present' ? (int) $t['points'] : null,
            ];
        }

        return $out;
    }
}
