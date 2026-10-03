<?php

namespace Tests\Unit;

use App\Support\SemesterStats;
use PHPUnit\Framework\TestCase;

class SemesterStatsTest extends TestCase
{
    private const TODAY = '2026-10-21';

    private const PERIOD = ['from' => '2026-09-08', 'to' => '2026-10-30'];

    private function row(string $date, string $status, ?int $points, int $student = 1): array
    {
        return ['student_id' => $student, 'work_date' => $date, 'status' => $status, 'points' => $points];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        return [
            $this->row('2026-09-08', 'present', 2), $this->row('2026-09-09', 'present', 4),
            $this->row('2026-09-22', 'present', 1), $this->row('2026-10-06', 'absent', null),
            $this->row('2026-10-07', 'present', 6),
            $this->row('2026-09-08', 'present', 0, 2), $this->row('2026-09-22', 'absent', null, 2),
            $this->row('2026-10-20', 'present', 3, 2),
            $this->row('2026-08-31', 'present', 2), // before the period: previous-period data
            $this->row('2026-10-22', 'present', 40), // after today: ignored
        ];
    }

    public function test_kpis_coverage_and_attendance(): void
    {
        $r = (new SemesterStats)->compute(self::PERIOD, $this->rows(), [1, 2], self::TODAY);

        $this->assertSame(['from' => '2026-09-08', 'to' => '2026-10-30', 'effective_to' => '2026-10-21'], $r['period']);
        $this->assertSame(16, $r['kpi']['total_points']);
        $this->assertSame(6, $r['kpi']['present_days_recorded']);
        $this->assertSame(2, $r['kpi']['absences']);
        $this->assertEqualsWithDelta(16 / 6, $r['kpi']['mean_per_present_day'], 1e-9);
        $this->assertSame(['days_with_entries' => 6, 'school_days_elapsed' => 32, 'rate' => 6 / 32], $r['coverage']);
        $this->assertSame(['present' => 6, 'absent' => 2, 'recorded' => 8, 'rate' => 0.75], $r['attendance']);
        $this->assertNull($r['comparison']);
        $this->assertTrue($r['has_data']);
    }

    public function test_weekly_series_has_gaps_for_empty_weeks_and_iso_week_buckets(): void
    {
        $r = (new SemesterStats)->compute(self::PERIOD, $this->rows(), [1, 2], self::TODAY);

        $this->assertSame(
            ['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19'],
            array_column($r['weeks'], 'monday'),
        );
        $this->assertSame('2026-09-08', $r['weeks'][0]['from'], 'first bucket is clipped to the period');
        $this->assertSame([2.0, null, 1.0, null, 6.0, null, 3.0], $r['class_series']);
        $this->assertSame([6, null, 1, null, 6, null, 3], $r['class_totals']);
        $this->assertSame([3.0, null, 1.0, null, 6.0, null, null], $r['series'][1]);
        $this->assertSame([0.0, null, null, null, null, null, 3.0], $r['series'][2]);
    }

    public function test_student_rows_and_chronological_log(): void
    {
        $r = (new SemesterStats)->compute(self::PERIOD, $this->rows(), [2, 1, 3], self::TODAY);

        $this->assertSame([2, 1, 3], array_keys($r['students']), 'caller order is preserved');
        $this->assertSame(13, $r['students'][1]['total_points']);
        $this->assertSame(4, $r['students'][1]['present_days']);
        $this->assertSame(5, $r['students'][1]['days_recorded']);
        $this->assertSame(1, $r['students'][1]['absences']);
        $this->assertSame(4, $r['students'][1]['participation_days']);
        $this->assertSame(3.25, $r['students'][1]['average']);
        $this->assertNull($r['students'][3]['average']);
        $this->assertSame([], $r['log'][3]);
        $this->assertSame(
            [['date' => '2026-10-07', 'status' => 'present', 'points' => 6], ['date' => '2026-10-06', 'status' => 'absent', 'points' => null]],
            array_slice($r['log'][1], 0, 2),
        );
        $this->assertSame('2026-09-08', end($r['log'][1])['date']);
    }

    public function test_comparison_and_trend_only_with_a_previous_period_that_has_data(): void
    {
        $stats = new SemesterStats;
        $prev = ['from' => '2026-08-24', 'to' => '2026-09-04'];
        $r = $stats->compute(self::PERIOD, $this->rows(), [1, 2], self::TODAY, $prev);

        $this->assertEqualsWithDelta(16 / 6 - 2, $r['comparison']['mean_delta'], 1e-9);
        $this->assertSame(2.0, $r['comparison']['previous_mean']);
        $this->assertSame(1.25, $r['students'][1]['trend']['delta']);
        $this->assertNull($r['students'][2]['trend'], 'student 2 has nothing in the previous period');

        $empty = $stats->compute(self::PERIOD, $this->rows(), [1], self::TODAY, ['from' => '2026-06-01', 'to' => '2026-06-30']);
        $this->assertNull($empty['comparison']);
        $this->assertNull($empty['students'][1]['trend']);
    }

    public function test_period_not_started_or_unresolved_or_empty(): void
    {
        $stats = new SemesterStats;

        $future = $stats->compute(['from' => '2026-11-02', 'to' => '2027-01-29'], $this->rows(), [1], self::TODAY);
        $this->assertFalse($future['has_data']);
        $this->assertSame([], $future['weeks']);
        $this->assertSame(0, $future['coverage']['school_days_elapsed']);
        $this->assertNull($future['coverage']['rate']);
        $this->assertNull($future['kpi']['mean_per_present_day']);
        $this->assertSame([], $future['series'][1]);

        $unresolved = $stats->compute(['from' => null, 'to' => null], [], [1], self::TODAY);
        $this->assertFalse($unresolved['has_data']);
        $this->assertNull($unresolved['attendance']['rate']);
        $this->assertSame([], $unresolved['log'][1]);

        $none = $stats->compute(self::PERIOD, [], [1], self::TODAY);
        $this->assertFalse($none['has_data']);
        $this->assertSame(32, $none['coverage']['school_days_elapsed']);
        $this->assertSame(0.0, $none['coverage']['rate']);
        $this->assertSame([null, null, null, null, null, null, null], $none['class_series']);
    }

    public function test_only_absences_and_only_zero_days(): void
    {
        $stats = new SemesterStats;
        $abs = $stats->compute(self::PERIOD, [$this->row('2026-09-08', 'absent', null)], [1], self::TODAY);
        $this->assertTrue($abs['has_data']);
        $this->assertNull($abs['kpi']['mean_per_present_day']);
        $this->assertSame(0.0, $abs['attendance']['rate']);
        $this->assertSame([null, null, null, null, null, null, null], $abs['series'][1]);

        $zero = $stats->compute(self::PERIOD, [$this->row('2026-09-08', 'present', 0)], [1], self::TODAY);
        $this->assertSame(0.0, $zero['kpi']['mean_per_present_day']);
        $this->assertSame(0.0, $zero['series'][1][0]);
    }

    public function test_school_days_count_weekdays_inclusively(): void
    {
        $stats = new SemesterStats;
        $this->assertSame(5, $stats->schoolDays('2026-09-28', '2026-10-02'));
        $this->assertSame(0, $stats->schoolDays('2026-10-03', '2026-10-04'));
        $this->assertSame(1, $stats->schoolDays('2026-10-02', '2026-10-04'));
        $this->assertSame([], $stats->weeklyAverages([], '2026-10-05', '2026-10-01'));
    }
}
