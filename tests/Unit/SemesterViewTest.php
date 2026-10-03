<?php

namespace Tests\Unit;

use App\Support\SemesterView;
use PHPUnit\Framework\TestCase;

class SemesterViewTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function data(bool $comparison = false): array
    {
        $full = ['key' => 'full', 'label' => 'Full Semester', 'from' => '2026-09-08', 'to' => '2026-09-18', 'configured' => true];
        $weeks = [['monday' => '2026-09-07', 'from' => '2026-09-08', 'to' => '2026-09-11'], ['monday' => '2026-09-14', 'from' => '2026-09-14', 'to' => '2026-09-18']];

        return [
            'periods' => ['full' => $full],
            'selected' => $full,
            'previous' => null,
            'students' => [
                ['id' => 1, 'name' => 'Alex Rivera', 'preferred_name' => null, 'student_number' => 'S-1', 'archived' => false],
                ['id' => 2, 'name' => 'Old Student', 'preferred_name' => '', 'student_number' => null, 'archived' => true],
            ],
            'notes' => [1 => [['date' => '2026-09-10', 'body' => 'Good lab work.']]],
            'stats' => [
                'has_data' => true,
                'kpi' => ['total_points' => 7, 'present_days_recorded' => 2, 'absences' => 1, 'mean_per_present_day' => 3.5],
                'coverage' => ['days_with_entries' => 2, 'school_days_elapsed' => 9, 'rate' => 2 / 9],
                'attendance' => ['present' => 2, 'absent' => 1, 'recorded' => 3, 'rate' => 2 / 3],
                'comparison' => $comparison ? ['previous_mean' => 2.0, 'mean_delta' => 1.5, 'previous_attendance_rate' => 0.5, 'attendance_rate_delta' => 0.1667] : null,
                'weeks' => $weeks,
                'class_series' => [4.0, 3.0],
                'class_totals' => [4, 3],
                'students' => [
                    1 => ['student_id' => 1, 'total_points' => 7, 'present_days' => 2, 'days_recorded' => 3, 'absences' => 1, 'participation_days' => 2, 'average' => 3.5, 'trend' => null],
                    2 => ['student_id' => 2, 'total_points' => 0, 'present_days' => 0, 'days_recorded' => 0, 'absences' => 0, 'participation_days' => 0, 'average' => null, 'trend' => null],
                ],
                'series' => [1 => [4.0, 3.0], 2 => [null, null]],
                'log' => [1 => [['date' => '2026-09-15', 'status' => 'absent', 'points' => null], ['date' => '2026-09-10', 'status' => 'present', 'points' => 1]]],
            ],
        ];
    }

    public function test_kpi_text_and_charts_are_built_from_the_stats_only(): void
    {
        $v = SemesterView::build($this->data(), '2026-09-18');

        $this->assertSame(7, $v['kpis']['total_points']['value']);
        $this->assertSame('3.50', $v['kpis']['mean']['value']);
        $this->assertSame('of 9 school days', $v['kpis']['coverage']['unit']);
        $this->assertSame(22, $v['kpis']['coverage']['percent']);
        $this->assertSame('67%', $v['kpis']['attendance']['rate_text']);
        // 1 active student x 9 school days - 3 recorded entries.
        $this->assertSame('6 not recorded', $v['kpis']['attendance']['not_recorded_text']);
        $this->assertCount(2, $v['kpis']['total_points']['chart']['bars']);
        $this->assertCount(2, $v['kpis']['mean']['chart']['dots']);
        $this->assertStringContainsString('Week 1 (Sep 8 – Sep 11): 4 points', $v['kpis']['total_points']['chart']['alt']);
        $this->assertSame(1, $v['enrolled']);
    }

    public function test_comparison_badges_exist_only_with_a_previous_period(): void
    {
        $this->assertNull(SemesterView::build($this->data(false), '2026-09-18')['kpis']['mean']['delta']);

        $data = $this->data(true);
        $data['previous'] = ['key' => 'q1', 'label' => 'Q1 / Midterm', 'from' => '2026-08-31', 'to' => '2026-09-04', 'configured' => true];
        $v = SemesterView::build($data, '2026-09-18');

        $this->assertSame(['text' => '+1.50', 'direction' => 'up', 'label' => 'vs Q1 / Midterm'], $v['kpis']['mean']['delta']);
        $this->assertSame('+16.7 percentage points', $v['kpis']['attendance']['delta']['text']);
        $this->assertSame('Trend vs Q1 / Midterm', $v['trend_header']);
        $this->assertTrue($v['has_trend']);
    }

    public function test_rows_carry_initials_formatted_numbers_and_notes_in_the_period(): void
    {
        $data = $this->data();
        $data['notes'][1][] = ['date' => '2026-08-01', 'body' => 'Before the period.'];

        $rows = SemesterView::build($data, '2026-09-18')['rows'];

        $this->assertSame('AR', $rows[0]['initials']);
        $this->assertSame('3.50', $rows[0]['average_text']);
        $this->assertSame(['2026-09-10'], array_column($rows[0]['notes'], 'date'));
        $this->assertSame('No data', $rows[1]['average_text']);
        $this->assertNull($rows[1]['average']);
        $this->assertNull($rows[1]['preferred_name']);
        $this->assertTrue($rows[1]['archived']);
    }

    public function test_student_payload_for_a_student_missing_from_the_stats_is_empty_not_an_error(): void
    {
        $student = ['id' => 99, 'name' => 'Late Joiner', 'preferred_name' => null, 'student_number' => null, 'archived' => true];

        $payload = SemesterView::student($this->data(), $student, ['date' => '2026-09-18', 'status' => 'absent', 'points' => null], '2026-09-18');

        $this->assertSame(0, $payload['stats']['total_points']);
        $this->assertSame('No data', $payload['stats']['average_text']);
        $this->assertSame([], $payload['log']);
        $this->assertFalse($payload['chart']['enough']);
        $this->assertSame('Today · Absent', $payload['today']['text']);
    }

    public function test_student_chart_is_enough_with_two_weeks_and_summarises_low_and_peak(): void
    {
        $student = ['id' => 1, 'name' => 'Alex Rivera', 'preferred_name' => null, 'student_number' => null, 'archived' => false];

        $payload = SemesterView::student($this->data(), $student, ['date' => '2026-09-17', 'status' => 'present', 'points' => 3], '2026-09-18');

        $this->assertTrue($payload['chart']['enough']);
        $this->assertSame(['lowest' => 'Lowest 3.00 (W2)', 'peak' => 'Peak 4.00 (W1)'], $payload['chart']['summary']);
        $this->assertSame('Thu, Sep 17 · Present · 3', $payload['today']['text']);
        $this->assertSame(['2026-09-15', '2026-09-10'], array_column($payload['log'], 'date'));
    }
}
