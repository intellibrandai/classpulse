<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Presentation data for Semester Analytics: turns ReportData::semester() into ready-to-print strings, chart geometry
 * and per-student rows. Pure (no DB, no request). The page renders it with Blade and
 * GET /api/classes/{class}/students/{student}/semester returns student() as JSON, so the JavaScript only paints
 * values and never computes a total.
 */
final class SemesterView
{
    public const PAGE_SIZE = 25;

    public const KPI_WIDTH = 120.0;

    public const KPI_HEIGHT = 40.0;

    public const CHART_WIDTH = 300.0;

    public const CHART_HEIGHT = 120.0;

    /**
     * @param  array<string, mixed>  $data  ReportData::semester()
     * @return array<string, mixed>
     */
    public static function build(array $data, string $today): array
    {
        $stats = $data['stats'];
        $selected = $data['selected'];
        $previous = $data['previous'];
        $average = new ParticipationStats;

        $periods = [];
        foreach ($data['periods'] as $key => $period) {
            $periods[] = [
                'key' => $key,
                'label' => $period['label'],
                'range_label' => self::rangeLabel($period['from'], $period['to']),
                'selected' => $key === $selected['key'],
            ];
        }

        $weeks = $stats['weeks'];
        $kpi = $stats['kpi'];
        $coverage = $stats['coverage'];
        $att = $stats['attendance'];
        $comparison = $stats['comparison'];
        $previousLabel = $previous['label'] ?? null;

        $activeIds = [];
        foreach ($data['students'] as $s) {
            if (! $s['archived']) {
                $activeIds[] = $s['id'];
            }
        }

        $delta = null;
        if ($comparison !== null && $previousLabel !== null) {
            $rounded = round($comparison['mean_delta'], 2);
            $delta = [
                'text' => ($rounded > 0 ? '+' : ($rounded < 0 ? '-' : '')).number_format(abs($rounded), 2, '.', ''),
                'direction' => $rounded > 0 ? 'up' : ($rounded < 0 ? 'down' : 'flat'),
                'label' => 'vs '.$previousLabel,
            ];
        }
        $attDelta = null;
        if ($comparison !== null && $previousLabel !== null && $comparison['attendance_rate_delta'] !== null) {
            $rounded = round($comparison['attendance_rate_delta'] * 100, 1);
            $attDelta = [
                'text' => ($rounded > 0 ? '+' : ($rounded < 0 ? '-' : '')).number_format(abs($rounded), 1, '.', '').' percentage points',
                'direction' => $rounded > 0 ? 'up' : ($rounded < 0 ? 'down' : 'flat'),
                'label' => 'vs '.$previousLabel,
            ];
        }

        $notRecorded = 0;
        foreach ($activeIds as $id) {
            $notRecorded += max(0, $coverage['school_days_elapsed'] - ($stats['students'][$id]['days_recorded'] ?? 0));
        }

        $presentDays = $kpi['present_days_recorded'];
        $hasCoverage = $coverage['school_days_elapsed'] > 0;
        $kpis = [
            'total_points' => [
                'value' => $kpi['total_points'],
                'sub' => $presentDays === 0 ? 'Nothing recorded yet' : 'Across '.$presentDays.($presentDays === 1 ? ' present day' : ' present days'),
                'chart' => self::barChart($stats['class_totals'], $weeks),
            ],
            'mean' => [
                'value' => $average->formatAverage($kpi['mean_per_present_day']),
                'has_data' => $kpi['mean_per_present_day'] !== null,
                'delta' => $delta,
                'chart' => self::lineChart($stats['class_series'], $weeks, self::KPI_WIDTH, self::KPI_HEIGHT, 4.0, 'Class average per present day'),
            ],
            'coverage' => [
                'value' => $coverage['days_with_entries'],
                'unit' => 'of '.$coverage['school_days_elapsed'].($coverage['school_days_elapsed'] === 1 ? ' school day' : ' school days'),
                'has_data' => $hasCoverage,
                'percent' => $coverage['rate'] === null ? null : (int) round($coverage['rate'] * 100),
                'sub' => $coverage['rate'] === null
                    ? 'No school days have passed in this period yet'
                    : (int) round($coverage['rate'] * 100).'% of elapsed school days have records',
            ],
            'attendance' => [
                'rate_text' => WeeklyStats::formatRate($att['rate']),
                'has_data' => $att['rate'] !== null,
                'percent' => $att['rate'] === null ? null : (int) round($att['rate'] * 100),
                'absences_text' => $att['absent'].($att['absent'] === 1 ? ' absence recorded' : ' absences recorded'),
                'not_recorded_text' => $notRecorded.' not recorded',
                'delta' => $attDelta,
            ],
        ];

        $hasTrend = $comparison !== null;
        $notes = $data['notes'];
        $rows = [];
        foreach ($data['students'] as $s) {
            $own = $stats['students'][$s['id']] ?? ['total_points' => 0, 'present_days' => 0, 'days_recorded' => 0, 'absences' => 0, 'average' => null, 'trend' => null];
            $trend = null;
            if ($hasTrend && $own['trend'] !== null) {
                $rounded = round($own['trend']['delta'], 2);
                $trend = [
                    'text' => ($rounded > 0 ? '+' : ($rounded < 0 ? '-' : '')).number_format(abs($rounded), 2, '.', ''),
                    'direction' => $rounded > 0 ? 'up' : ($rounded < 0 ? 'down' : 'flat'),
                ];
            }
            $rows[] = [
                'id' => $s['id'],
                'name' => $s['name'],
                'preferred_name' => filled($s['preferred_name']) ? $s['preferred_name'] : null,
                'student_number' => filled($s['student_number']) ? $s['student_number'] : null,
                'archived' => $s['archived'],
                'initials' => Initials::of($s['name']),
                'total_points' => $own['total_points'],
                'present_days' => $own['present_days'],
                'days_recorded' => $own['days_recorded'],
                'absences' => $own['absences'],
                'average' => $own['average'] === null ? null : round($own['average'], 4),
                'average_text' => $average->formatAverage($own['average']),
                'trend' => $trend,
                'notes' => self::periodNotes($notes[$s['id']] ?? [], $selected['from'], $selected['to']),
            ];
        }

        $lines = [
            'Total points: '.$kpi['total_points'],
            'Mean per present day: '.$average->formatAverage($kpi['mean_per_present_day']).($kpi['mean_per_present_day'] === null ? '' : ' points'),
            'Session coverage: '.$coverage['days_with_entries'].' of '.$coverage['school_days_elapsed'].' school days with records',
            'Attendance: '.$kpis['attendance']['rate_text'].' recorded attendance rate, '.$kpis['attendance']['absences_text'].', '.$kpis['attendance']['not_recorded_text'],
        ];
        if ($delta !== null) {
            $lines[] = 'Mean per present day '.$delta['label'].': '.$delta['text'];
        }

        return [
            'period' => [
                'key' => $selected['key'],
                'label' => $selected['label'],
                'from' => $selected['from'],
                'to' => $selected['to'],
                'range_label' => self::rangeLabel($selected['from'], $selected['to']),
            ],
            'periods' => $periods,
            'has_quarters' => isset($data['periods']['q1']) || isset($data['periods']['q2']),
            'previous_label' => $previousLabel,
            'has_data' => $stats['has_data'],
            'enrolled' => count($activeIds),
            'kpis' => $kpis,
            'rows' => $rows,
            'has_trend' => $hasTrend,
            'trend_header' => $hasTrend && $previousLabel !== null ? 'Trend vs '.$previousLabel : null,
            'print_lines' => $lines,
            'page_size' => self::PAGE_SIZE,
        ];
    }

    /**
     * The inspector payload of one student for the selected period.
     *
     * @param  array<string, mixed>  $data  ReportData::semester()
     * @param  array{id: int, name: string, preferred_name: ?string, student_number: ?string, archived: bool}  $student
     * @param  array{date: string, status: string, points: ?int}  $day  the student's entry on the school date shown as "today"
     * @return array<string, mixed>
     */
    public static function student(array $data, array $student, array $day, string $today): array
    {
        $stats = $data['stats'];
        $selected = $data['selected'];
        $id = $student['id'];
        $average = new ParticipationStats;
        $own = $stats['students'][$id] ?? ['total_points' => 0, 'present_days' => 0, 'days_recorded' => 0, 'absences' => 0, 'average' => null];
        $series = $stats['series'][$id] ?? array_fill(0, count($stats['weeks']), null);

        $log = [];
        foreach ($stats['log'][$id] ?? [] as $r) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $r['date'], 'UTC');
            $present = $r['status'] === 'present';
            $log[] = [
                'date' => $r['date'],
                'label' => $date->format('M j, Y'),
                'weekday' => $date->format('D'),
                'status' => $r['status'],
                'status_text' => $present ? 'Present' : 'Absent',
                'points' => $r['points'],
                'points_text' => $present ? $r['points'].($r['points'] === 1 ? ' pt' : ' pts') : '—',
            ];
        }

        $notes = [];
        foreach ($data['notes'][$id] ?? [] as $n) {
            $notes[] = self::noteRow($n);
        }

        $state = match ($day['status']) {
            'present' => 'Present · '.$day['points'],
            'absent' => 'Absent',
            default => 'Not recorded',
        };
        $dayDate = CarbonImmutable::createFromFormat('!Y-m-d', $day['date'], 'UTC');
        $chart = self::studentChart($series, $stats['weeks']);

        return [
            'student' => [
                'id' => $id,
                'name' => $student['name'],
                'preferred_name' => filled($student['preferred_name']) ? $student['preferred_name'] : null,
                'student_number' => filled($student['student_number']) ? $student['student_number'] : null,
                'archived' => $student['archived'],
                'initials' => Initials::of($student['name']),
            ],
            'period' => [
                'key' => $selected['key'],
                'label' => $selected['label'],
                'from' => $selected['from'],
                'to' => $selected['to'],
                'range_label' => self::rangeLabel($selected['from'], $selected['to']),
            ],
            'today' => [
                'date' => $day['date'],
                'status' => $day['status'],
                'points' => $day['points'],
                'text' => ($day['date'] === $today ? 'Today' : $dayDate->format('D, M j')).' · '.$state,
            ],
            'stats' => [
                'total_points' => $own['total_points'],
                'present_days' => $own['present_days'],
                'days_recorded' => $own['days_recorded'],
                'present_text' => $own['present_days'].' of '.$own['days_recorded'].' recorded',
                'absences' => $own['absences'],
                'average_text' => $average->formatAverage($own['average']),
                'has_data' => $own['average'] !== null,
            ],
            'chart' => $chart,
            'log' => $log,
            'notes' => $notes,
        ];
    }

    /**
     * @param  array{date: string, body: string}  $note
     * @return array{date: string, label: string, weekday: string, body: string}
     */
    public static function noteRow(array $note): array
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $note['date'], 'UTC');

        return ['date' => $note['date'], 'label' => $date->format('M j, Y'), 'weekday' => $date->format('D'), 'body' => $note['body']];
    }

    public static function rangeLabel(?string $from, ?string $to): string
    {
        if ($from === null || $to === null) {
            return 'No dates recorded yet';
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC')->format('M j, Y').' – '.CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC')->format('M j, Y');
    }

    /**
     * Saved notes that fall inside the period (all of them when the period has no dates), newest first.
     *
     * @param  array<int, array{date: string, body: string}>  $notes
     * @return array<int, array{date: string, label: string, weekday: string, body: string}>
     */
    private static function periodNotes(array $notes, ?string $from, ?string $to): array
    {
        $out = [];
        foreach ($notes as $n) {
            if ($from !== null && $to !== null && ($n['date'] < $from || $n['date'] > $to)) {
                continue;
            }
            $out[] = self::noteRow($n);
        }

        return $out;
    }

    /**
     * @param  array<int, array{monday: string, from: string, to: string}>  $weeks
     */
    private static function weekTitle(int $index, array $week): string
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $week['from'], 'UTC')->format('M j');
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $week['to'], 'UTC')->format('M j');

        return 'Week '.($index + 1).' ('.$from.' – '.$to.')';
    }

    /**
     * @param  array<int, ?float>  $values
     * @param  array<int, array{monday: string, from: string, to: string}>  $weeks
     * @return array{width: float, height: float, has_data: bool, path: string, dots: array<int, array{x: float, y: float, title: string, is_last: bool}>, alt: string}
     */
    private static function lineChart(array $values, array $weeks, float $width, float $height, float $padding, string $what): array
    {
        $spark = Sparkline::build($values, $width, $height, $padding);
        $parts = [];
        foreach ($weeks as $i => $week) {
            $parts[] = self::weekTitle($i, $week).': '.(($values[$i] ?? null) === null ? 'no data' : number_format($values[$i], 2, '.', ''));
        }
        $dots = [];
        foreach ($spark['points'] as $p) {
            $dots[] = ['x' => $p['x'], 'y' => $p['y'], 'title' => $parts[$p['index']], 'is_last' => $p['index'] === ($spark['last']['index'] ?? -1)];
        }

        return [
            'width' => $spark['width'],
            'height' => $spark['height'],
            'has_data' => $spark['has_data'],
            'path' => $spark['path'],
            'dots' => $dots,
            'alt' => $what.' by week. '.($parts === [] ? 'No weeks yet.' : implode('; ', $parts)),
        ];
    }

    /**
     * @param  array<int, ?int>  $values
     * @param  array<int, array{monday: string, from: string, to: string}>  $weeks
     * @return array{width: float, height: float, has_data: bool, baseline_y: float, bars: array<int, array{x: float, y: float, width: float, height: float, is_gap: bool, is_peak: bool, title: string}>, alt: string}
     */
    private static function barChart(array $values, array $weeks): array
    {
        $series = BarSeries::build($values, self::KPI_WIDTH, self::KPI_HEIGHT, 2);
        $parts = [];
        $bars = [];
        foreach ($series['bars'] as $bar) {
            $label = self::weekTitle($bar['index'], $weeks[$bar['index']]).': '.($bar['value'] === null ? 'no data' : $bar['value'].' points');
            $parts[] = $label;
            $height = $bar['is_gap'] ? 0.0 : max($bar['height'], 2.0);
            $bars[] = [
                'x' => $bar['x'], 'y' => round($series['baseline_y'] - $height, 2), 'width' => $bar['width'],
                'height' => $height, 'is_gap' => $bar['is_gap'], 'is_peak' => $bar['is_peak'], 'title' => $label,
            ];
        }

        return [
            'width' => $series['width'],
            'height' => $series['height'],
            'has_data' => $series['has_data'],
            'baseline_y' => $series['baseline_y'],
            'bars' => $bars,
            'alt' => 'Total points by week. '.($parts === [] ? 'No weeks yet.' : implode('; ', $parts)),
        ];
    }

    /**
     * The inspector's weekly evolution chart: average per present day by week, gaps for weeks without a present day.
     *
     * @param  array<int, ?float>  $series
     * @param  array<int, array{monday: string, from: string, to: string}>  $weeks
     * @return array<string, mixed>
     */
    private static function studentChart(array $series, array $weeks): array
    {
        $padding = 14.0;
        $chart = self::lineChart($series, $weeks, self::CHART_WIDTH, self::CHART_HEIGHT, $padding, 'Average per present day');
        $withData = count(array_filter($series, fn ($v) => $v !== null));
        $n = count($series);
        $step = max(1, (int) ceil($n / 8));
        $labels = [];
        for ($i = 0; $i < $n; $i += $step) {
            $x = $n === 1 ? self::CHART_WIDTH / 2 : $padding + (self::CHART_WIDTH - 2 * $padding) * $i / ($n - 1);
            $labels[] = ['x' => round($x, 2), 'text' => 'W'.($i + 1)];
        }

        $summary = null;
        if ($withData >= 2) {
            $lowIndex = null;
            $highIndex = null;
            foreach ($series as $i => $v) {
                if ($v === null) {
                    continue;
                }
                if ($lowIndex === null || $v < $series[$lowIndex]) {
                    $lowIndex = $i;
                }
                if ($highIndex === null || $v > $series[$highIndex]) {
                    $highIndex = $i;
                }
            }
            $summary = [
                'lowest' => 'Lowest '.number_format($series[$lowIndex], 2, '.', '').' (W'.($lowIndex + 1).')',
                'peak' => 'Peak '.number_format($series[$highIndex], 2, '.', '').' (W'.($highIndex + 1).')',
            ];
        }

        $spark = Sparkline::build($series, self::CHART_WIDTH, self::CHART_HEIGHT, $padding);

        return $chart + [
            'enough' => $withData >= 2,
            'weeks' => $n,
            'weeks_with_data' => $withData,
            'empty_text' => 'Not enough weeks recorded yet',
            'baseline_y' => round(self::CHART_HEIGHT - $padding, 2),
            'top_y' => $padding,
            'max_text' => number_format($spark['max'], 1, '.', ''),
            'labels' => $labels,
            'summary' => $summary,
        ];
    }
}
