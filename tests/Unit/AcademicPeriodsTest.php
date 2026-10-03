<?php

namespace Tests\Unit;

use App\Support\AcademicPeriods;
use PHPUnit\Framework\TestCase;

class AcademicPeriodsTest extends TestCase
{
    public function test_full_semester_uses_the_class_dates_when_set(): void
    {
        $periods = AcademicPeriods::resolve('2026-09-08', '2027-01-29', [], '2026-09-10', '2026-10-01');

        $this->assertSame(['full'], array_keys($periods));
        $this->assertSame('Full Semester', $periods['full']['label']);
        $this->assertSame(['2026-09-08', '2027-01-29'], [$periods['full']['from'], $periods['full']['to']]);
        $this->assertTrue($periods['full']['configured']);
    }

    public function test_full_semester_falls_back_to_first_and_last_recorded_dates(): void
    {
        $periods = AcademicPeriods::resolve(null, null, [], '2026-09-10', '2026-10-01');

        $this->assertSame(['2026-09-10', '2026-10-01'], [$periods['full']['from'], $periods['full']['to']]);
        $this->assertFalse($periods['full']['configured']);
    }

    public function test_class_without_dates_or_data_has_an_unresolved_full_semester(): void
    {
        $periods = AcademicPeriods::resolve(null, null, [], null, null);

        $this->assertNull($periods['full']['from']);
        $this->assertNull($periods['full']['to']);
    }

    public function test_only_configured_quarters_are_offered_and_select_falls_back(): void
    {
        $periods = AcademicPeriods::resolve('2026-09-08', '2027-01-29', [
            'q2' => ['label' => 'Finals', 'starts_on' => '2026-11-16', 'ends_on' => '2027-01-29'],
        ], null, null);

        $this->assertSame(['full', 'q2'], array_keys($periods));
        $this->assertSame('Finals', AcademicPeriods::select($periods, 'q2')['label']);
        $this->assertSame('full', AcademicPeriods::select($periods, 'q1')['key']);
        $this->assertSame('full', AcademicPeriods::select($periods, null)['key']);
        $this->assertNull(AcademicPeriods::previous($periods, $periods['q2']));
    }

    public function test_q2_compares_with_q1_only_when_q1_exists(): void
    {
        $periods = AcademicPeriods::resolve(null, null, [
            'q1' => ['label' => 'Q1', 'starts_on' => '2026-09-08', 'ends_on' => '2026-11-13'],
            'q2' => ['label' => 'Q2', 'starts_on' => '2026-11-16', 'ends_on' => '2027-01-29'],
        ], null, null);

        $this->assertSame('q1', AcademicPeriods::previous($periods, $periods['q2'])['key']);
        $this->assertNull(AcademicPeriods::previous($periods, $periods['q1']));
        $this->assertNull(AcademicPeriods::previous($periods, $periods['full']));
    }

    public function test_valid_settings_have_no_errors(): void
    {
        $this->assertSame([], AcademicPeriods::validate('2026-09-08', '2027-01-29',
            ['start' => '2026-09-08', 'end' => '2026-11-13'],
            ['start' => '2026-11-14', 'end' => '2027-01-29']));
        $this->assertSame([], AcademicPeriods::validate(null, null, null, null));
        $this->assertSame([], AcademicPeriods::validate(null, null, ['start' => null, 'end' => null], null));
    }

    public function test_validation_rules(): void
    {
        $this->assertArrayHasKey('semester_end', AcademicPeriods::validate('2026-09-08', '2026-09-01', null, null));
        $this->assertArrayHasKey('q1_end', AcademicPeriods::validate(null, null, ['start' => '2026-10-02', 'end' => '2026-10-01'], null));
        $this->assertArrayHasKey('q1_end', AcademicPeriods::validate(null, null, ['start' => '2026-10-02', 'end' => null], null));
        $this->assertArrayHasKey('q2_end', AcademicPeriods::validate(null, null, null, ['start' => '2026-10-03', 'end' => null]));

        $outside = AcademicPeriods::validate('2026-09-08', '2027-01-29',
            ['start' => '2026-09-01', 'end' => '2026-11-13'],
            ['start' => '2026-11-14', 'end' => '2027-02-05']);
        $this->assertArrayHasKey('q1_start', $outside);
        $this->assertArrayHasKey('q2_end', $outside);

        $free = AcademicPeriods::validate(null, null, ['start' => '2020-01-01', 'end' => '2030-01-01'], null);
        $this->assertSame([], $free, 'without semester dates a quarter is not bounded');
    }

    public function test_overlap_is_rejected_but_touching_is_an_overlap_only_on_a_shared_day(): void
    {
        $overlap = AcademicPeriods::validate(null, null,
            ['start' => '2026-09-08', 'end' => '2026-11-13'],
            ['start' => '2026-11-13', 'end' => '2027-01-29']);
        $this->assertArrayHasKey('q2_start', $overlap);

        $adjacent = AcademicPeriods::validate(null, null,
            ['start' => '2026-09-08', 'end' => '2026-11-13'],
            ['start' => '2026-11-14', 'end' => '2027-01-29']);
        $this->assertSame([], $adjacent);

        $reversed = AcademicPeriods::validate(null, null,
            ['start' => '2026-11-14', 'end' => '2027-01-29'],
            ['start' => '2026-09-08', 'end' => '2026-11-13']);
        $this->assertSame([], $reversed, 'Q2 may be listed before Q1 as long as they do not overlap');
    }
}
