<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Presentation data for the Weekly Matrix: turns the ReportData::weekly() result into ready-to-print strings,
 * chart geometry and per-student numbers. Pure (no DB, no request). The page renders it with Blade and
 * GET /api/classes/{class}/weeks/{monday} returns the same array, so the JavaScript only paints values and never
 * computes a total.
 */
final class WeeklyView
{
    public const CHART_WIDTH = 120.0;

    public const CHART_HEIGHT = 40.0;

    /**
     * @param  array<string, mixed>  $week  ReportData::weekly()
     * @return array<string, mixed>
     */
    public static function build(array $week, string $today, string $className = ''): array
    {
        $stats = $week['stats'];
        $average = new ParticipationStats;
        $days = [];
        foreach ($week['days'] as $date) {
            $d = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
            $days[] = [
                'date' => $date,
                'weekday' => $d->format('D'),
                'weekday_long' => $d->format('l'),
                'label' => $d->format('M j'),
                'editable' => $date <= $today,
                'today' => $date === $today,
            ];
        }

        $students = [];
        foreach ($week['students'] as $s) {
            $own = $stats['students'][$s['id']] ?? ['total_points' => 0, 'present_days' => 0, 'absences' => 0, 'average' => null];
            $hasPresent = $own['present_days'] > 0;
            $students[] = [
                'id' => $s['id'],
                'archived' => $s['archived'],
                'cells' => array_map(fn (array $c, string $date) => ['date' => $date, 'status' => $c['status'], 'points' => $c['points']], $s['cells'], $week['days']),
                'total_points' => $own['total_points'],
                'total_text' => $hasPresent ? (string) $own['total_points'] : '—',
                'present_days' => $own['present_days'],
                'absences' => $own['absences'],
                'absences_text' => $own['absences'] === 0 ? '0' : $own['absences'].($own['absences'] === 1 ? ' Absence' : ' Absences'),
                'average' => $own['average'] === null ? null : round($own['average'], 4),
                'average_text' => $own['average'] === null ? '—' : $average->formatAverage($own['average']),
                'average_sub' => $hasPresent ? $own['total_points'].' pts / '.$own['present_days'].' d' : null,
            ];
        }

        $class = $stats['class'];
        $att = $stats['attendance'];
        $peak = $stats['peak_day'];
        $recordedDays = count(array_filter($week['days'], fn ($d, $i) => $stats['day_present'][$i] + $stats['day_absent'][$i] > 0, ARRAY_FILTER_USE_BOTH));

        $delta = null;
        if ($stats['comparison'] !== null) {
            $value = $stats['comparison']['average_delta'];
            $rounded = round($value, 2);
            $delta = [
                'text' => ($rounded > 0 ? '+' : ($rounded < 0 ? '-' : '')).number_format(abs($rounded), 2, '.', ''),
                'direction' => $rounded > 0 ? 'up' : ($rounded < 0 ? 'down' : 'flat'),
                'label' => 'vs previous week',
            ];
        }

        $pointValues = [];
        foreach ($week['days'] as $i => $date) {
            $pointValues[] = $stats['day_present'][$i] > 0 ? $stats['day_points'][$i] : null;
        }

        $summary = (new WeeklyStats)->summaryText($className, self::rangeLabel($week['days']), $stats);

        return [
            'monday' => $week['monday'],
            'range_label' => self::rangeLabel($week['days']),
            'days' => $days,
            'has_data' => $stats['has_data'],
            'kpis' => [
                'average' => [
                    'value' => $average->formatAverage($class['average']),
                    'has_data' => $class['average'] !== null,
                    'delta' => $delta,
                    'chart' => self::lineChart($stats['day_averages'], $days),
                ],
                'total_points' => [
                    'value' => $class['total_points'],
                    'sub' => $recordedDays === 0 ? 'Nothing recorded yet' : 'Across '.$recordedDays.($recordedDays === 1 ? ' day' : ' days').' with records',
                    'chart' => self::barChart($pointValues, $days),
                ],
                'attendance' => [
                    'rate_text' => WeeklyStats::formatRate($att['rate']),
                    'has_data' => $att['rate'] !== null,
                    'percent' => $att['rate'] === null ? null : (int) round($att['rate'] * 100),
                    'detail' => $att['rate'] === null
                        ? 'No recorded student-sessions yet'
                        : $att['present'].' of '.$att['recorded'].' recorded student-sessions',
                    'not_recorded_text' => $att['not_recorded'].' not recorded',
                ],
                'peak' => $peak === null ? null : [
                    'weekday' => $peak['weekday'],
                    'points_text' => $peak['points'].($peak['points'] === 1 ? ' point' : ' points'),
                    'present_text' => $peak['present'].' present that day',
                ],
            ],
            'students' => $students,
            'footer' => [
                'total_points' => $class['total_points'],
                'average_text' => $average->formatAverage($class['average']),
                'absences' => $class['absences'],
            ],
            'summary_text' => $summary,
        ];
    }

    /**
     * Heat class of a cell: '' for Not recorded, heat-absent, heat-0 (recorded zero), heat-low (1-2), heat-mid (3-5), heat-high (6+).
     */
    public static function heat(string $status, ?int $points): string
    {
        return match (true) {
            $status === 'absent' => 'heat-absent',
            $status !== 'present' => '',
            (int) $points >= 6 => 'heat-high',
            (int) $points >= 3 => 'heat-mid',
            (int) $points >= 1 => 'heat-low',
            default => 'heat-0',
        };
    }

    /**
     * @param  array<int, string>  $days
     */
    public static function rangeLabel(array $days): string
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $days[0], 'UTC');
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $days[4], 'UTC');

        return $from->format('M j').' – '.$to->format('M j, Y');
    }

    /**
     * @param  array<int, ?float>  $values
     * @param  array<int, array<string, mixed>>  $days
     * @return array{width: float, height: float, has_data: bool, path: string, dots: array<int, array{x: float, y: float, title: string, is_last: bool}>, alt: string}
     */
    private static function lineChart(array $values, array $days): array
    {
        $spark = Sparkline::build($values, self::CHART_WIDTH, self::CHART_HEIGHT, 4);
        $parts = [];
        foreach ($days as $i => $day) {
            $parts[] = $day['weekday'].': '.($values[$i] === null ? 'no data' : number_format($values[$i], 2, '.', ''));
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
            'alt' => 'Class average per present day by weekday. '.implode('; ', $parts),
        ];
    }

    /**
     * @param  array<int, ?int>  $values
     * @param  array<int, array<string, mixed>>  $days
     * @return array{width: float, height: float, has_data: bool, baseline_y: float, bars: array<int, array{x: float, y: float, width: float, height: float, is_gap: bool, is_peak: bool, title: string}>, alt: string}
     */
    private static function barChart(array $values, array $days): array
    {
        $series = BarSeries::build($values, self::CHART_WIDTH, self::CHART_HEIGHT, 6);
        $parts = [];
        $bars = [];
        foreach ($series['bars'] as $bar) {
            $label = $days[$bar['index']]['weekday'].': '.($bar['value'] === null ? 'no data' : $bar['value'].' points');
            $parts[] = $label;
            $height = $bar['is_gap'] ? 0.0 : max($bar['height'], 2.0);
            $bars[] = [
                'x' => $bar['x'], 'y' => round($series['baseline_y'] - $height, 2), 'width' => $bar['width'],
                'height' => $height,
                'is_gap' => $bar['is_gap'], 'is_peak' => $bar['is_peak'], 'title' => $label,
            ];
        }

        return [
            'width' => $series['width'],
            'height' => $series['height'],
            'has_data' => $series['has_data'],
            'baseline_y' => $series['baseline_y'],
            'bars' => $bars,
            'alt' => 'Points per weekday. '.implode('; ', $parts),
        ];
    }
}
