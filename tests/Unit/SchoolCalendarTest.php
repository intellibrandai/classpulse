<?php

namespace Tests\Unit;

use App\Support\SchoolCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SchoolCalendarTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function freeze(string $when): SchoolCalendar
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($when, 'America/Toronto'));

        return new SchoolCalendar('America/Toronto');
    }

    public function test_today_uses_toronto_midnight(): void
    {
        $this->assertSame('2026-10-21', $this->freeze('2026-10-21 23:30')->today());
        $this->assertSame('2026-10-22', $this->freeze('2026-10-22 00:30')->today());
    }

    public function test_previous_and_next_weekday_skip_weekends_and_dst(): void
    {
        $calendar = new SchoolCalendar('America/Toronto');

        $this->assertSame('2026-03-06', $calendar->previousWeekday('2026-03-09'));
        $this->assertSame('2026-10-26', $calendar->nextWeekday('2026-10-23'));
        $this->assertSame('2026-10-30', $calendar->previousWeekday('2026-11-02'));
        $this->assertSame('2026-11-02', $calendar->nextWeekday('2026-10-30'));
        $this->assertSame('2026-03-09', $calendar->nextWeekday('2026-03-06'));
        $this->assertSame('2026-10-21', $calendar->previousWeekday('2026-10-22'));
    }

    public function test_weekday_week_start_and_week_days(): void
    {
        $calendar = new SchoolCalendar('America/Toronto');

        $this->assertFalse($calendar->isWeekday('2026-10-24'));
        $this->assertFalse($calendar->isWeekday('2026-10-25'));
        $this->assertTrue($calendar->isWeekday('2026-10-23'));
        $this->assertSame('2026-10-19', $calendar->weekStart('2026-10-21'));
        $this->assertSame('2026-10-19', $calendar->weekStart('2026-10-25'));
        $this->assertSame('2026-03-02', $calendar->weekStart('2026-03-08'));
        $this->assertSame(
            ['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23'],
            $calendar->weekDays('2026-10-19'),
        );
        $this->assertSame(
            ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'],
            $calendar->weekDays('2026-11-02'),
        );
    }

    public function test_default_date_and_future_check(): void
    {
        $this->assertSame('2026-10-21', $this->freeze('2026-10-21 10:00')->defaultDate());
        $this->assertSame('2026-10-23', $this->freeze('2026-10-24 10:00')->defaultDate());
        $this->assertSame('2026-10-23', $this->freeze('2026-10-25 10:00')->defaultDate());

        $calendar = $this->freeze('2026-10-21 23:30');
        $this->assertFalse($calendar->isFuture('2026-10-21'));
        $this->assertTrue($calendar->isFuture('2026-10-22'));
    }

    public function test_today_across_the_dst_changes(): void
    {
        $this->assertSame('2026-03-08', $this->freeze('2026-03-08 23:30')->today());
        $this->assertSame('2026-03-09', $this->freeze('2026-03-09 00:30')->today());
        $this->assertSame('2026-11-01', $this->freeze('2026-11-01 23:30')->today());
        $this->assertSame('2026-11-02', $this->freeze('2026-11-02 00:30')->today());
    }
}
