<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Semester Analytics numbers for one selected period (SPEC section 5). Pure: rows in, plain arrays out.
 *
 * Row shape: ['student_id' => int, 'work_date' => 'Y-m-d', 'status' => 'present'|'absent', 'points' => ?int].
 * School days are every Monday to Friday (no holiday calendar exists); "elapsed" stops at today.
 */
final class SemesterStats
{
    public function __construct(
        private readonly ParticipationStats $stats = new ParticipationStats,
        private readonly SchoolCalendar $calendar = new SchoolCalendar,
    ) {}

    /**
     * @param  array{from: ?string, to: ?string}  $period  inclusive range; null bounds mean the period is unresolved
     * @param  array<int, array{student_id: int, work_date: string, status: string, points: ?int}>  $rows  any rows; those outside the ranges are ignored
     * @param  array<int, int>  $studentIds  students that get a row (caller orders them); order is preserved
     * @param  string  $today  'Y-m-d'
     * @param  ?array{from: ?string, to: ?string}  $previous  the previous comparable period, or null when none exists
     * @return array{
     *     period: array{from: ?string, to: ?string, effective_to: ?string},
     *     has_data: bool,
     *     kpi: array{total_points: int, present_days_recorded: int, absences: int, mean_per_present_day: ?float},
     *     coverage: array{days_with_entries: int, school_days_elapsed: int, rate: ?float},
     *     attendance: array{present: int, absent: int, recorded: int, rate: ?float},
     *     comparison: ?array{previous_mean: float, mean_delta: float, previous_attendance_rate: ?float, attendance_rate_delta: ?float},
     *     weeks: array<int, array{monday: string, from: string, to: string}>,
     *     class_series: array<int, ?float>,
     *     class_totals: array<int, ?int>,
     *     students: array<int, array{student_id: int, total_points: int, present_days: int, days_recorded: int, absences: int, participation_days: int, average: ?float, trend: ?array{previous_average: float, delta: float}}>,
     *     series: array<int, array<int, ?float>>,
     *     log: array<int, array<int, array{date: string, status: string, points: ?int}>>
     * }
     */
    public function compute(array $period, array $rows, array $studentIds, string $today, ?array $previous = null): array
    {
        $from = $period['from'];
        $to = $period['to'];
        $effectiveTo = ($from === null || $to === null) ? null : min($to, $today);
        $resolved = $from !== null && $effectiveTo !== null && $from <= $effectiveTo;

        $inPeriod = $resolved ? $this->within($rows, $from, $effectiveTo) : [];
        $class = $this->stats->summarize($inPeriod);

        $dates = [];
        foreach ($inPeriod as $row) {
            $dates[$row['work_date']] = true;
        }
        $elapsed = $resolved ? $this->schoolDays($from, $effectiveTo) : 0;

        $prevRows = [];
        $prevSummary = null;
        if ($previous !== null && $previous['from'] !== null && $previous['to'] !== null) {
            $prevEnd = min($previous['to'], $today);
            if ($previous['from'] <= $prevEnd) {
                $prevRows = $this->within($rows, $previous['from'], $prevEnd);
                $prevSummary = $this->stats->summarize($prevRows);
            }
        }

        $byStudent = [];
        foreach ($inPeriod as $row) {
            $byStudent[$row['student_id']][] = $row;
        }
        $prevByStudent = [];
        foreach ($prevRows as $row) {
            $prevByStudent[$row['student_id']][] = $row;
        }

        $weeks = [];
        $classSeries = [];
        $classTotals = [];
        $seriesByStudent = [];
        if ($resolved) {
            $buckets = $this->stats->weekly($inPeriod, $from, $effectiveTo, $this->calendar);
            foreach ($buckets as $monday => $bucket) {
                $weeks[] = ['monday' => $monday, 'from' => $bucket['from'], 'to' => $bucket['to']];
                $classSeries[] = $bucket['average'];
                $classTotals[] = $bucket['present_days_recorded'] === 0 ? null : $bucket['total_points'];
            }
        }

        $students = [];
        $log = [];
        foreach ($studentIds as $id) {
            $own = $byStudent[$id] ?? [];
            $s = $this->stats->summarize($own);
            $trend = null;
            if ($prevSummary !== null && isset($prevByStudent[$id]) && $s['average'] !== null) {
                $p = $this->stats->summarize($prevByStudent[$id]);
                if ($p['average'] !== null) {
                    $trend = ['previous_average' => $p['average'], 'delta' => $s['average'] - $p['average']];
                }
            }
            $students[$id] = [
                'student_id' => $id,
                'total_points' => $s['total_points'],
                'present_days' => $s['present_days_recorded'],
                'days_recorded' => $s['days_recorded'],
                'absences' => $s['absences'],
                'participation_days' => $s['participation_days'],
                'average' => $s['average'],
                'trend' => $trend,
            ];

            $seriesByStudent[$id] = $resolved
                ? array_values(array_map(fn (array $b) => $b['average'], $this->stats->weekly($own, $from, $effectiveTo, $this->calendar)))
                : [];

            usort($own, fn (array $a, array $b) => strcmp($b['work_date'], $a['work_date']));
            $log[$id] = array_map(fn (array $r) => [
                'date' => $r['work_date'],
                'status' => $r['status'],
                'points' => $r['status'] === 'present' ? (int) $r['points'] : null,
            ], $own);
        }

        return [
            'period' => ['from' => $from, 'to' => $to, 'effective_to' => $effectiveTo],
            'has_data' => $class['days_recorded'] > 0,
            'kpi' => [
                'total_points' => $class['total_points'],
                'present_days_recorded' => $class['present_days_recorded'],
                'absences' => $class['absences'],
                'mean_per_present_day' => $class['average'],
            ],
            'coverage' => [
                'days_with_entries' => count($dates),
                'school_days_elapsed' => $elapsed,
                'rate' => $elapsed === 0 ? null : (float) (count($dates) / $elapsed),
            ],
            'attendance' => [
                'present' => $class['present_days_recorded'],
                'absent' => $class['absences'],
                'recorded' => $class['days_recorded'],
                'rate' => $class['days_recorded'] === 0 ? null : (float) ($class['present_days_recorded'] / $class['days_recorded']),
            ],
            'comparison' => $this->compare($class, $prevSummary),
            'weeks' => $weeks,
            'class_series' => $classSeries,
            'class_totals' => $classTotals,
            'students' => $students,
            'series' => $seriesByStudent,
            'log' => $log,
        ];
    }

    /**
     * Weekly average per present day over the ISO weeks of a range; weeks without a present day are gaps (null).
     * Used for the semester trend and the Daily panel's cadence chart.
     *
     * @param  array<int, array{student_id: int, work_date: string, status: string, points: ?int}>  $rows
     * @return array<int, array{monday: string, from: string, to: string, present_days: int, average: ?float}>
     */
    public function weeklyAverages(array $rows, string $from, string $to): array
    {
        if ($from > $to) {
            return [];
        }
        $out = [];
        foreach ($this->stats->weekly($rows, $from, $to, $this->calendar) as $monday => $bucket) {
            $out[] = [
                'monday' => $monday,
                'from' => $bucket['from'],
                'to' => $bucket['to'],
                'present_days' => $bucket['present_days_recorded'],
                'average' => $bucket['average'],
            ];
        }

        return $out;
    }

    /**
     * Number of Monday-to-Friday dates in the inclusive range.
     */
    public function schoolDays(string $from, string $to): int
    {
        $d = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');
        $count = 0;
        while ($d <= $end) {
            if ($d->dayOfWeekIso <= 5) {
                $count++;
            }
            $d = $d->addDay();
        }

        return $count;
    }

    /**
     * @param  array<int, array{student_id: int, work_date: string, status: string, points: ?int}>  $rows
     * @return array<int, array{student_id: int, work_date: string, status: string, points: ?int}>
     */
    private function within(array $rows, string $from, string $to): array
    {
        return array_values(array_filter($rows, fn (array $r) => $r['work_date'] >= $from && $r['work_date'] <= $to));
    }

    /**
     * @param  array{present_days_recorded: int, days_recorded: int, average: ?float}  $current
     * @param  ?array{present_days_recorded: int, days_recorded: int, average: ?float}  $previous
     * @return ?array{previous_mean: float, mean_delta: float, previous_attendance_rate: ?float, attendance_rate_delta: ?float}
     */
    private function compare(array $current, ?array $previous): ?array
    {
        if ($previous === null || $previous['average'] === null || $current['average'] === null) {
            return null;
        }
        $prevRate = $previous['days_recorded'] === 0 ? null : (float) ($previous['present_days_recorded'] / $previous['days_recorded']);
        $rate = $current['days_recorded'] === 0 ? null : (float) ($current['present_days_recorded'] / $current['days_recorded']);

        return [
            'previous_mean' => $previous['average'],
            'mean_delta' => $current['average'] - $previous['average'],
            'previous_attendance_rate' => $prevRate,
            'attendance_rate_delta' => ($prevRate === null || $rate === null) ? null : $rate - $prevRate,
        ];
    }
}
