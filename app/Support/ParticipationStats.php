<?php

namespace App\Support;

final class ParticipationStats
{
    /**
     * @param  array<int, array{student_id: int, work_date: string, status: string, points: ?int}>  $rows
     * @return array{total_points: int, present_days_recorded: int, absences: int, days_recorded: int, participation_days: int, average: ?float}
     */
    public function summarize(array $rows, ?string $from = null, ?string $to = null): array
    {
        $total = 0;
        $present = 0;
        $absences = 0;
        $participation = 0;

        foreach ($rows as $row) {
            if (($from !== null && $row['work_date'] < $from) || ($to !== null && $row['work_date'] > $to)) {
                continue;
            }
            if ($row['status'] === 'present') {
                $points = (int) $row['points'];
                $total += $points;
                $present++;
                if ($points > 0) {
                    $participation++;
                }
            } elseif ($row['status'] === 'absent') {
                $absences++;
            }
        }

        return [
            'total_points' => $total,
            'present_days_recorded' => $present,
            'absences' => $absences,
            'days_recorded' => $present + $absences,
            'participation_days' => $participation,
            'average' => $present === 0 ? null : (float) ($total / $present),
        ];
    }

    /**
     * Buckets rows by Monday; the first bucket's from and the last bucket's to are clipped to the period.
     *
     * @param  array<int, array{student_id: int, work_date: string, status: string, points: ?int}>  $rows
     * @return array<string, array<string, mixed>>
     */
    public function weekly(array $rows, string $from, string $to, SchoolCalendar $calendar): array
    {
        $weeks = [];
        $monday = $calendar->weekStart($from);
        $lastMonday = $calendar->weekStart($to);

        while ($monday <= $lastMonday) {
            $days = $calendar->weekDays($monday);
            $bucketFrom = max($monday, $from);
            $bucketTo = min($days[4], $to);
            $weeks[$monday] = ['from' => $bucketFrom, 'to' => $bucketTo] + $this->summarize($rows, $bucketFrom, $bucketTo);
            $monday = $calendar->nextWeekday($days[4]);
        }

        return $weeks;
    }

    public function formatAverage(?float $average): string
    {
        return $average === null ? 'No data' : number_format($average, 2, '.', '');
    }
}
