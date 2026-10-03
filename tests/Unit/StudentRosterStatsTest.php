<?php

namespace Tests\Unit;

use App\Support\StudentRoster;
use PHPUnit\Framework\TestCase;

class StudentRosterStatsTest extends TestCase
{
    private function row(string $date, string $status, ?int $points, int $student): array
    {
        return ['student_id' => $student, 'work_date' => $date, 'status' => $status, 'points' => $points];
    }

    public function test_average_per_present_day_and_todays_status(): void
    {
        $rows = [
            $this->row('2026-10-19', 'present', 4, 1), $this->row('2026-10-20', 'absent', null, 1), $this->row('2026-10-21', 'present', 2, 1),
            $this->row('2026-10-21', 'absent', null, 2),
            $this->row('2026-09-01', 'present', 9, 3),
        ];
        $r = (new StudentRoster)->build([1, 2, 3, 4], $rows, '2026-10-21', '2026-10-01', '2026-12-31');

        $this->assertSame(3.0, $r[1]['average']);
        $this->assertSame(2, $r[1]['present_days']);
        $this->assertSame(1, $r[1]['absences']);
        $this->assertSame('present', $r[1]['today']);
        $this->assertSame(2, $r[1]['today_points']);
        $this->assertNull($r[2]['average'], 'only absent: No data');
        $this->assertSame('absent', $r[2]['today']);
        $this->assertNull($r[2]['today_points']);
        $this->assertNull($r[3]['average'], 'entry outside the semester is excluded from the average');
        $this->assertSame('none', $r[3]['today']);
        $this->assertSame('none', $r[4]['today']);
        $this->assertSame([1, 2, 3, 4], array_keys($r));
    }

    public function test_status_of_a_recorded_zero_is_present_with_zero_points(): void
    {
        $r = (new StudentRoster)->build([1], [$this->row('2026-10-21', 'present', 0, 1)], '2026-10-21');

        $this->assertSame(0.0, $r[1]['average']);
        $this->assertSame('present', $r[1]['today']);
        $this->assertSame(0, $r[1]['today_points']);
    }
}
