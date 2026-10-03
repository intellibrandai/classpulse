<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Weekly Matrix numbers (SPEC section 5). Pure: rows in, plain arrays out. Reuses ParticipationStats::summarize.
 *
 * Row shape everywhere: ['student_id' => int, 'work_date' => 'Y-m-d', 'status' => 'present'|'absent', 'points' => ?int].
 */
final class WeeklyStats
{
    public function __construct(private readonly ParticipationStats $stats = new ParticipationStats) {}

    /**
     * @param  array<int, string>  $days  the five dates Monday to Friday (SchoolCalendar::weekDays)
     * @param  array<int, array{student_id: int, work_date: string, status: string, points: ?int}>  $rows  rows of the week (others are ignored)
     * @param  array<int, int>  $activeStudentIds  active students; they define the "possible" sessions. Archived students
     *                                             that have entries only contribute their recorded sessions.
     * @param  array<int, array{student_id: int, work_date: string, status: string, points: ?int}>  $previousRows  rows of the previous week
     * @param  ?string  $today  'Y-m-d'; days after it are not yet possible sessions. Null counts all five days.
     * @return array{
     *     days: array<int, string>,
     *     class: array{total_points: int, present_days_recorded: int, absences: int, days_recorded: int, participation_days: int, average: ?float},
     *     day_points: array<int, int>, day_present: array<int, int>, day_absent: array<int, int>,
     *     day_averages: array<int, ?float>,
     *     attendance: array{present: int, absent: int, recorded: int, not_recorded: int, possible: int, rate: ?float},
     *     peak_day: ?array{date: string, weekday: string, points: int, present: int},
     *     comparison: ?array{previous_average: float, average_delta: float, previous_total_points: int, total_points_delta: int},
     *     students: array<int, array{total_points: int, present_days: int, absences: int, average: ?float}>,
     *     has_data: bool
     * }
     */
    public function compute(array $days, array $rows, array $activeStudentIds, array $previousRows = [], ?string $today = null): array
    {
        $from = $days[0];
        $to = $days[4];
        $rows = array_values(array_filter($rows, fn (array $r) => $r['work_date'] >= $from && $r['work_date'] <= $to));

        $class = $this->stats->summarize($rows, $from, $to);

        $dayPoints = $dayPresent = $dayAbsent = [];
        $dayAverages = [];
        foreach ($days as $i => $day) {
            $s = $this->stats->summarize($rows, $day, $day);
            $dayPoints[$i] = $s['total_points'];
            $dayPresent[$i] = $s['present_days_recorded'];
            $dayAbsent[$i] = $s['absences'];
            $dayAverages[$i] = $s['average'];
        }

        $byStudent = [];
        foreach ($rows as $row) {
            $byStudent[$row['student_id']][] = $row;
        }

        $students = [];
        $ids = array_values(array_unique(array_merge($activeStudentIds, array_keys($byStudent))));
        foreach ($ids as $id) {
            $s = $this->stats->summarize($byStudent[$id] ?? []);
            $students[$id] = [
                'total_points' => $s['total_points'],
                'present_days' => $s['present_days_recorded'],
                'absences' => $s['absences'],
                'average' => $s['average'],
            ];
        }

        $elapsed = count(array_filter($days, fn (string $d) => $today === null || $d <= $today));
        $possible = count($activeStudentIds) * $elapsed;
        $activeSet = array_flip($activeStudentIds);
        $recordedActive = count(array_filter($rows, fn (array $r) => isset($activeSet[$r['student_id']])));
        $recorded = $class['days_recorded'];

        return [
            'days' => $days,
            'class' => $class,
            'day_points' => $dayPoints,
            'day_present' => $dayPresent,
            'day_absent' => $dayAbsent,
            'day_averages' => $dayAverages,
            'attendance' => [
                'present' => $class['present_days_recorded'],
                'absent' => $class['absences'],
                'recorded' => $recorded,
                'not_recorded' => max(0, $possible - $recordedActive),
                'possible' => $possible,
                'rate' => $recorded === 0 ? null : (float) ($class['present_days_recorded'] / $recorded),
            ],
            'peak_day' => $this->peakDay($days, $dayPoints, $dayPresent),
            'comparison' => $this->compare($class, $previousRows, $days),
            'students' => $students,
            'has_data' => $recorded > 0,
        ];
    }

    /**
     * "83%" for a rate in 0..1, or "No data" when there is no recorded attendance.
     */
    public static function formatRate(?float $rate): string
    {
        return $rate === null ? 'No data' : number_format($rate * 100, 0, '.', '').'%';
    }

    /**
     * Plain-text weekly summary for "Copy Summary". Uses only defined metrics.
     *
     * @param  array<string, mixed>  $week  the compute() result
     */
    public function summaryText(string $className, string $weekLabel, array $week): string
    {
        $lines = ["{$className} - week of {$weekLabel}"];
        if (! $week['has_data']) {
            $lines[] = 'No participation recorded this week.';

            return implode("\n", $lines)."\n";
        }
        $lines[] = 'Class weekly average: '.$this->stats->formatAverage($week['class']['average']).' points per present day';
        $lines[] = 'Total points: '.$week['class']['total_points'];
        $a = $week['attendance'];
        $lines[] = 'Recorded attendance: '.self::formatRate($a['rate'])." ({$a['present']} of {$a['recorded']} recorded student-sessions present; {$a['not_recorded']} not recorded)";
        $lines[] = 'Absences: '.$week['class']['absences'];
        if ($week['peak_day'] !== null) {
            $lines[] = "Peak day: {$week['peak_day']['weekday']} ({$week['peak_day']['points']} points)";
        }
        if ($week['comparison'] !== null) {
            $delta = $week['comparison']['average_delta'];
            $lines[] = 'Versus previous week: '.($delta >= 0 ? '+' : '-').number_format(abs($delta), 2, '.', '').' points per present day';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<int, string>  $days
     * @param  array<int, int>  $dayPoints
     * @param  array<int, int>  $dayPresent
     * @return ?array{date: string, weekday: string, points: int, present: int}
     */
    private function peakDay(array $days, array $dayPoints, array $dayPresent): ?array
    {
        $best = null;
        foreach ($days as $i => $day) {
            if ($dayPresent[$i] === 0) {
                continue;
            }
            if ($best === null || $dayPoints[$i] > $dayPoints[$best]) {
                $best = $i;
            }
        }

        return $best === null ? null : [
            'date' => $days[$best],
            'weekday' => CarbonImmutable::createFromFormat('!Y-m-d', $days[$best], 'UTC')->format('l'),
            'points' => $dayPoints[$best],
            'present' => $dayPresent[$best],
        ];
    }

    /**
     * @param  array{total_points: int, present_days_recorded: int, average: ?float}  $current
     * @param  array<int, array{student_id: int, work_date: string, status: string, points: ?int}>  $previousRows
     * @param  array<int, string>  $days
     * @return ?array{previous_average: float, average_delta: float, previous_total_points: int, total_points_delta: int}
     */
    private function compare(array $current, array $previousRows, array $days): ?array
    {
        if ($current['average'] === null) {
            return null;
        }
        $monday = CarbonImmutable::createFromFormat('!Y-m-d', $days[0], 'UTC')->subDays(7);
        $previous = $this->stats->summarize($previousRows, $monday->format('Y-m-d'), $monday->addDays(4)->format('Y-m-d'));
        if ($previous['average'] === null) {
            return null;
        }

        return [
            'previous_average' => $previous['average'],
            'average_delta' => $current['average'] - $previous['average'],
            'previous_total_points' => $previous['total_points'],
            'total_points_delta' => $current['total_points'] - $previous['total_points'],
        ];
    }
}
