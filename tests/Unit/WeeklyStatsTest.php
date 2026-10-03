<?php

namespace Tests\Unit;

use App\Support\SchoolCalendar;
use App\Support\WeeklyStats;
use PHPUnit\Framework\TestCase;

class WeeklyStatsTest extends TestCase
{
    private function row(string $date, string $status, ?int $points, int $student = 1): array
    {
        return ['student_id' => $student, 'work_date' => $date, 'status' => $status, 'points' => $points];
    }

    /**
     * @return array<int, string>
     */
    private function week(string $monday = '2026-10-19'): array
    {
        return (new SchoolCalendar)->weekDays($monday);
    }

    public function test_week_totals_averages_and_per_day_arrays(): void
    {
        $rows = [
            $this->row('2026-10-19', 'present', 3), $this->row('2026-10-20', 'present', 0),
            $this->row('2026-10-21', 'absent', null), $this->row('2026-10-23', 'present', 5),
            $this->row('2026-10-19', 'present', 1, 2), $this->row('2026-10-20', 'present', 2, 2),
            $this->row('2026-10-21', 'present', 4, 3),
            $this->row('2026-10-26', 'present', 50, 1),
        ];
        $w = (new WeeklyStats)->compute($this->week(), $rows, [1, 2]);

        $this->assertSame(15, $w['class']['total_points']);
        $this->assertSame(6, $w['class']['present_days_recorded']);
        $this->assertSame(2.5, $w['class']['average']);
        $this->assertSame(1, $w['class']['absences']);
        $this->assertSame([4, 2, 4, 0, 5], $w['day_points']);
        $this->assertSame([2, 2, 1, 0, 1], $w['day_present']);
        $this->assertSame([0, 0, 1, 0, 0], $w['day_absent']);
        $this->assertSame([2.0, 1.0, 4.0, null, 5.0], $w['day_averages']);
        $this->assertTrue($w['has_data']);
    }

    public function test_recorded_attendance_excludes_not_recorded_and_archived_students_do_not_add_possible_sessions(): void
    {
        $rows = [
            $this->row('2026-10-19', 'present', 3), $this->row('2026-10-20', 'present', 0),
            $this->row('2026-10-21', 'absent', null), $this->row('2026-10-23', 'present', 5),
            $this->row('2026-10-19', 'present', 1, 2), $this->row('2026-10-20', 'present', 2, 2),
            $this->row('2026-10-21', 'present', 4, 3), // student 3 is archived: recorded, but not a possible session
        ];
        $a = (new WeeklyStats)->compute($this->week(), $rows, [1, 2])['attendance'];

        $this->assertSame(6, $a['present']);
        $this->assertSame(1, $a['absent']);
        $this->assertSame(7, $a['recorded']);
        $this->assertSame(10, $a['possible']);
        $this->assertSame(4, $a['not_recorded']);
        $this->assertEqualsWithDelta(6 / 7, $a['rate'], 1e-9);
        $this->assertSame('86%', WeeklyStats::formatRate($a['rate']));
    }

    public function test_future_days_are_not_possible_sessions(): void
    {
        $a = (new WeeklyStats)->compute($this->week(), [], [1, 2, 3], [], '2026-10-21')['attendance'];

        $this->assertSame(9, $a['possible']);
        $this->assertSame(9, $a['not_recorded']);
    }

    public function test_only_not_recorded_is_no_data(): void
    {
        $w = (new WeeklyStats)->compute($this->week(), [], [1, 2]);

        $this->assertFalse($w['has_data']);
        $this->assertNull($w['class']['average']);
        $this->assertNull($w['attendance']['rate']);
        $this->assertSame('No data', WeeklyStats::formatRate($w['attendance']['rate']));
        $this->assertNull($w['peak_day']);
        $this->assertNull($w['comparison']);
        $this->assertSame([null, null, null, null, null], $w['day_averages']);
        $this->assertSame(10, $w['attendance']['not_recorded']);
        $this->assertNull($w['students'][1]['average']);
    }

    public function test_only_absences_have_a_zero_rate_but_no_average_and_no_peak(): void
    {
        $w = (new WeeklyStats)->compute($this->week(), [$this->row('2026-10-19', 'absent', null), $this->row('2026-10-20', 'absent', null)], [1]);

        $this->assertSame(0.0, $w['attendance']['rate']);
        $this->assertSame('0%', WeeklyStats::formatRate($w['attendance']['rate']));
        $this->assertNull($w['class']['average']);
        $this->assertNull($w['peak_day']);
        $this->assertNull($w['comparison']);
        $this->assertSame(2, $w['students'][1]['absences']);
        $this->assertTrue($w['has_data']);
    }

    public function test_peak_day_ties_go_to_the_earliest_day(): void
    {
        $rows = [
            $this->row('2026-10-21', 'present', 3), $this->row('2026-10-19', 'present', 3),
            $this->row('2026-10-22', 'present', 1),
        ];
        $peak = (new WeeklyStats)->compute($this->week(), $rows, [1])['peak_day'];

        $this->assertSame(['date' => '2026-10-19', 'weekday' => 'Monday', 'points' => 3, 'present' => 1], $peak);
    }

    public function test_comparison_only_when_the_previous_week_has_a_present_day(): void
    {
        $current = [$this->row('2026-10-19', 'present', 4), $this->row('2026-10-20', 'present', 2)];
        $stats = new WeeklyStats;

        $with = $stats->compute($this->week(), $current, [1], [$this->row('2026-10-12', 'present', 1)]);
        $this->assertSame(1.0, $with['comparison']['previous_average']);
        $this->assertSame(2.0, $with['comparison']['average_delta']);
        $this->assertSame(1, $with['comparison']['previous_total_points']);
        $this->assertSame(5, $with['comparison']['total_points_delta']);

        $this->assertNull($stats->compute($this->week(), $current, [1], [$this->row('2026-10-12', 'absent', null)])['comparison']);
        $this->assertNull($stats->compute($this->week(), $current, [1], [$this->row('2026-10-05', 'present', 9)])['comparison'], 'two weeks back is not the previous week');
        $this->assertNull($stats->compute($this->week(), [], [1], [$this->row('2026-10-12', 'present', 1)])['comparison'], 'no current data');
    }

    public function test_iso_week_crossing_months_counts_both_sides(): void
    {
        $days = $this->week('2026-09-28');
        $rows = [$this->row('2026-09-30', 'present', 2), $this->row('2026-10-01', 'present', 4), $this->row('2026-10-05', 'present', 9)];
        $w = (new WeeklyStats)->compute($days, $rows, [1]);

        $this->assertSame(['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'], $days);
        $this->assertSame(6, $w['class']['total_points']);
        $this->assertSame([0, 0, 2, 4, 0], $w['day_points']);
        $this->assertSame('2026-10-01', $w['peak_day']['date']);
        $this->assertSame('Thursday', $w['peak_day']['weekday']);
    }

    public function test_per_student_figures_and_gaps(): void
    {
        $rows = [
            $this->row('2026-10-19', 'present', 3), $this->row('2026-10-23', 'present', 0), $this->row('2026-10-21', 'absent', null),
            $this->row('2026-10-20', 'present', 5, 2),
        ];
        $s = (new WeeklyStats)->compute($this->week(), $rows, [1, 2, 4])['students'];

        $this->assertSame(['total_points' => 3, 'present_days' => 2, 'absences' => 1, 'average' => 1.5], $s[1]);
        $this->assertSame(5, $s[2]['total_points']);
        $this->assertSame(['total_points' => 0, 'present_days' => 0, 'absences' => 0, 'average' => null], $s[4]);
    }

    public function test_copy_summary_text_uses_only_defined_metrics(): void
    {
        $stats = new WeeklyStats;
        $empty = $stats->summaryText('HNL 2O', 'Oct 19, 2026', $stats->compute($this->week(), [], [1]));
        $this->assertSame("HNL 2O - week of Oct 19, 2026\nNo participation recorded this week.\n", $empty);

        $w = $stats->compute($this->week(), [$this->row('2026-10-19', 'present', 4), $this->row('2026-10-20', 'absent', null)], [1], [$this->row('2026-10-12', 'present', 1)]);
        $text = $stats->summaryText('HNL 2O', 'Oct 19, 2026', $w);
        $this->assertStringContainsString('Class weekly average: 4.00 points per present day', $text);
        $this->assertStringContainsString('Recorded attendance: 50% (1 of 2 recorded student-sessions present; 3 not recorded)', $text);
        $this->assertStringContainsString('Peak day: Monday (4 points)', $text);
        $this->assertStringContainsString('Versus previous week: +3.00', $text);
    }
}
