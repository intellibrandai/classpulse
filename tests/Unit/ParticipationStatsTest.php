<?php

namespace Tests\Unit;

use App\Support\ParticipationStats;
use App\Support\SchoolCalendar;
use PHPUnit\Framework\TestCase;

class ParticipationStatsTest extends TestCase
{
    /**
     * @return array{student_id: int, work_date: string, status: string, points: ?int}
     */
    private function row(string $date, string $status, ?int $points, int $student = 1): array
    {
        return ['student_id' => $student, 'work_date' => $date, 'status' => $status, 'points' => $points];
    }

    public function test_student_summary_counts_zero_days_and_absences(): void
    {
        $stats = new ParticipationStats;
        $summary = $stats->summarize([
            $this->row('2026-10-19', 'present', 3),
            $this->row('2026-10-20', 'present', 0),
            $this->row('2026-10-21', 'present', 5),
            $this->row('2026-10-22', 'absent', null),
        ]);

        $this->assertSame(8, $summary['total_points']);
        $this->assertSame(3, $summary['present_days_recorded']);
        $this->assertSame(1, $summary['absences']);
        $this->assertSame(4, $summary['days_recorded']);
        $this->assertSame(2, $summary['participation_days']);
        $this->assertSame('2.67', $stats->formatAverage($summary['average']));
    }

    public function test_no_present_days_is_no_data_not_zero(): void
    {
        $stats = new ParticipationStats;
        $summary = $stats->summarize([$this->row('2026-10-19', 'absent', null)]);

        $this->assertNull($summary['average']);
        $this->assertSame('No data', $stats->formatAverage($summary['average']));
        $this->assertSame('No data', $stats->formatAverage($stats->summarize([])['average']));
        $this->assertSame('0.00', $stats->formatAverage(0.0));
    }

    public function test_semester_average_is_not_the_mean_of_weekly_averages(): void
    {
        $stats = new ParticipationStats;
        $calendar = new SchoolCalendar('America/Toronto');
        $rows = [
            $this->row('2026-10-19', 'present', 10),
            $this->row('2026-10-26', 'present', 1),
            $this->row('2026-10-27', 'present', 1),
            $this->row('2026-10-28', 'present', 1),
            $this->row('2026-10-29', 'present', 1),
        ];

        $period = $stats->summarize($rows, '2026-10-19', '2026-10-30');
        $this->assertSame(2.8, $period['average']);

        $weekly = $stats->weekly($rows, '2026-10-19', '2026-10-30', $calendar);
        $this->assertSame(['2026-10-19', '2026-10-26'], array_keys($weekly));
        $this->assertSame(10.0, $weekly['2026-10-19']['average']);
        $this->assertSame(1.0, $weekly['2026-10-26']['average']);
        $this->assertNotSame(5.5, $period['average']);
    }

    public function test_weekly_buckets_are_clipped_to_the_period(): void
    {
        $stats = new ParticipationStats;
        $calendar = new SchoolCalendar('America/Toronto');
        $rows = [
            $this->row('2026-10-19', 'present', 9),
            $this->row('2026-10-21', 'present', 2),
            $this->row('2026-10-29', 'present', 4),
            $this->row('2026-10-30', 'present', 7),
        ];

        $weekly = $stats->weekly($rows, '2026-10-21', '2026-10-29', $calendar);

        $this->assertSame(['from' => '2026-10-21', 'to' => '2026-10-23'], array_intersect_key($weekly['2026-10-19'], ['from' => 1, 'to' => 1]));
        $this->assertSame(['from' => '2026-10-26', 'to' => '2026-10-29'], array_intersect_key($weekly['2026-10-26'], ['from' => 1, 'to' => 1]));
        $this->assertSame(2, $weekly['2026-10-19']['total_points']);
        $this->assertSame(4, $weekly['2026-10-26']['total_points']);
    }
}
