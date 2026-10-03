<?php

namespace Tests\Unit;

use App\Support\SchoolCalendar;
use App\Support\WeeklyStats;
use App\Support\WeeklyView;
use PHPUnit\Framework\TestCase;

class WeeklyViewTest extends TestCase
{
    /**
     * @param  array<int, array{student_id: int, work_date: string, status: string, points: ?int}>  $rows
     * @param  array<int, array{student_id: int, work_date: string, status: string, points: ?int}>  $previous
     * @return array<string, mixed>
     */
    private function build(array $rows, array $previous = [], string $today = '2026-10-26'): array
    {
        $days = (new SchoolCalendar)->weekDays('2026-10-19');
        $stats = (new WeeklyStats)->compute($days, $rows, [1, 2], array_merge($previous, $rows), $today);
        $cells = fn (int $id) => array_map(function (string $d) use ($rows, $id) {
            foreach ($rows as $r) {
                if ($r['student_id'] === $id && $r['work_date'] === $d) {
                    return ['status' => $r['status'], 'points' => $r['status'] === 'present' ? $r['points'] : null];
                }
            }

            return ['status' => 'none', 'points' => null];
        }, $days);
        $week = [
            'monday' => '2026-10-19', 'days' => $days, 'stats' => $stats,
            'students' => [
                ['id' => 1, 'name' => 'A', 'preferred_name' => null, 'student_number' => null, 'archived' => false, 'cells' => $cells(1)],
                ['id' => 2, 'name' => 'B', 'preferred_name' => null, 'student_number' => null, 'archived' => false, 'cells' => $cells(2)],
            ],
            'charts' => [],
        ];

        return WeeklyView::build($week, $today, 'C1');
    }

    private function row(int $id, string $date, string $status, ?int $points): array
    {
        return ['student_id' => $id, 'work_date' => $date, 'status' => $status, 'points' => $points];
    }

    public function test_heat_classes_keep_the_four_steps_absent_and_not_recorded(): void
    {
        $this->assertSame('', WeeklyView::heat('none', null));
        $this->assertSame('heat-absent', WeeklyView::heat('absent', null));
        $this->assertSame('heat-0', WeeklyView::heat('present', 0));
        $this->assertSame('heat-low', WeeklyView::heat('present', 2));
        $this->assertSame('heat-mid', WeeklyView::heat('present', 5));
        $this->assertSame('heat-high', WeeklyView::heat('present', 6));
    }

    public function test_range_label_and_editable_days(): void
    {
        $view = $this->build([], [], '2026-10-21');

        $this->assertSame('Oct 19 – Oct 23, 2026', $view['range_label']);
        $this->assertSame([true, true, true, false, false], array_column($view['days'], 'editable'));
        $this->assertSame([false, false, true, false, false], array_column($view['days'], 'today'));
    }

    public function test_an_empty_week_has_gaps_not_zeros_in_the_charts(): void
    {
        $view = $this->build([]);

        $this->assertSame('No data', $view['kpis']['average']['value']);
        $this->assertFalse($view['kpis']['average']['chart']['has_data']);
        $this->assertSame('', $view['kpis']['average']['chart']['path']);
        $this->assertSame([true, true, true, true, true], array_column($view['kpis']['total_points']['chart']['bars'], 'is_gap'));
        $this->assertNull($view['kpis']['peak']);
        $this->assertSame('—', $view['students'][0]['total_text']);
    }

    public function test_a_recorded_zero_day_is_a_bar_not_a_gap_and_a_loss_is_signed(): void
    {
        $current = [$this->row(1, '2026-10-19', 'present', 0), $this->row(1, '2026-10-20', 'present', 2)];
        $previous = [$this->row(1, '2026-10-12', 'present', 5)];
        $view = $this->build($current, $previous);

        $bars = $view['kpis']['total_points']['chart']['bars'];
        $this->assertFalse($bars[0]['is_gap']);
        $this->assertTrue($bars[2]['is_gap']);
        $this->assertSame('-4.00', $view['kpis']['average']['delta']['text']);
        $this->assertSame('down', $view['kpis']['average']['delta']['direction']);
        $this->assertSame('Tuesday', $view['kpis']['peak']['weekday']);
        $this->assertSame('2 points', $view['kpis']['peak']['points_text']);
        $this->assertSame('2 pts / 2 d', $view['students'][0]['average_sub']);
        $this->assertSame('1.00', $view['students'][0]['average_text']);
    }
}
