<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentSemesterApiTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    private Student $alex;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 10:00', 'America/Toronto'));
        $this->actingAs(User::factory()->create());
        $this->class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'semester_start' => '2026-09-08', 'semester_end' => '2027-01-29']);
        $this->class->academicPeriods()->create(['kind' => 'q1', 'label' => 'Q1 / Midterm', 'starts_on' => '2026-09-08', 'ends_on' => '2026-10-02']);
        $this->alex = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Alex Rivera', 'preferred_name' => 'Al', 'student_number' => 'S-1001']);
        foreach ([['2026-09-14', 'present', 3], ['2026-09-15', 'present', 0], ['2026-09-16', 'absent', null], ['2026-10-19', 'present', 5], ['2026-10-20', 'present', 4]] as [$date, $status, $points]) {
            ParticipationEntry::factory()->create(['school_class_id' => $this->class->id, 'student_id' => $this->alex->id, 'work_date' => $date, 'status' => $status, 'points' => $points, 'restore_points' => null]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function url(?Student $student = null, string $query = '', ?SchoolClass $class = null): string
    {
        return '/api/classes/'.($class ?? $this->class)->id.'/students/'.($student ?? $this->alex)->id.'/semester'.$query;
    }

    public function test_full_semester_payload_shape_and_numbers(): void
    {
        StudentNote::factory()->create(['school_class_id' => $this->class->id, 'student_id' => $this->alex->id, 'note_date' => '2026-09-15', 'body' => 'Helped a classmate.']);

        $response = $this->getJson($this->url())->assertOk();
        $data = $response->json('data');

        $this->assertSame(['student', 'period', 'today', 'stats', 'chart', 'log', 'notes'], array_keys($data));
        $this->assertSame(['id' => $this->alex->id, 'name' => 'Alex Rivera', 'preferred_name' => 'Al', 'student_number' => 'S-1001', 'archived' => false, 'initials' => 'AR'], $data['student']);
        $this->assertSame('full', $data['period']['key']);
        $this->assertSame('Sep 8, 2026 – Jan 29, 2027', $data['period']['range_label']);
        $this->assertSame([
            'total_points' => 12, 'present_days' => 4, 'days_recorded' => 5, 'present_text' => '4 of 5 recorded',
            'absences' => 1, 'average_text' => '3.00', 'has_data' => true,
        ], $data['stats']);
        $this->assertSame(['date' => '2026-10-26', 'status' => 'none', 'points' => null, 'text' => 'Today · Not recorded'], $data['today']);
        $this->assertSame([['date' => '2026-09-15', 'label' => 'Sep 15, 2026', 'weekday' => 'Tue', 'body' => 'Helped a classmate.']], $data['notes']);
    }

    public function test_audit_log_is_newest_first_with_status_text_and_points(): void
    {
        $log = $this->getJson($this->url())->json('data.log');

        $this->assertSame(['2026-10-20', '2026-10-19', '2026-09-16', '2026-09-15', '2026-09-14'], array_column($log, 'date'));
        $this->assertSame(
            ['date' => '2026-10-20', 'label' => 'Oct 20, 2026', 'weekday' => 'Tue', 'status' => 'present', 'status_text' => 'Present', 'points' => 4, 'points_text' => '4 pts'],
            $log[0],
        );
        $this->assertSame(['status' => 'absent', 'status_text' => 'Absent', 'points' => null, 'points_text' => '—'], array_intersect_key($log[2], array_flip(['status', 'status_text', 'points', 'points_text'])));
        $this->assertSame('0 pts', $log[3]['points_text']);
    }

    public function test_weekly_chart_has_gaps_labels_and_needs_two_weeks_of_data(): void
    {
        $chart = $this->getJson($this->url())->json('data.chart');

        $this->assertTrue($chart['enough']);
        $this->assertSame(8, $chart['weeks']);
        $this->assertSame(2, $chart['weeks_with_data']);
        $this->assertCount(2, $chart['dots']);
        $this->assertStringStartsWith('M', $chart['path']);
        $this->assertSame(['lowest' => 'Lowest 1.50 (W2)', 'peak' => 'Peak 4.50 (W7)'], $chart['summary']);
        $this->assertStringContainsString('Week 1 (Sep 8 – Sep 11): no data', $chart['alt']);
        $this->assertStringContainsString('Week 2 (Sep 14 – Sep 18): 1.50', $chart['alt']);
        $this->assertSame('W1', $chart['labels'][0]['text']);

        // Q1 only has one week with data: not enough for a line.
        $q1 = $this->getJson($this->url(null, '?period=q1'))->json('data.chart');
        $this->assertFalse($q1['enough']);
        $this->assertSame('Not enough weeks recorded yet', $q1['empty_text']);
        $this->assertNull($q1['summary']);
    }

    public function test_period_param_changes_the_numbers_and_unknown_falls_back_to_full(): void
    {
        $this->assertSame(3, $this->getJson($this->url(null, '?period=q1'))->json('data.stats.total_points'));
        $this->assertSame('q1', $this->getJson($this->url(null, '?period=q1'))->json('data.period.key'));
        $this->assertSame(12, $this->getJson($this->url(null, '?period=zzz'))->json('data.stats.total_points'));
        $this->assertSame('full', $this->getJson($this->url(null, '?period=q2'))->json('data.period.key'));
        $this->getJson($this->url(null, '?period[]=q1'))->assertStatus(422)->assertJsonPath('error.code', 'validation');
    }

    public function test_today_reports_the_entry_of_the_school_date(): void
    {
        ParticipationEntry::factory()->create(['school_class_id' => $this->class->id, 'student_id' => $this->alex->id, 'work_date' => '2026-10-26', 'status' => 'present', 'points' => 2, 'restore_points' => null]);

        $this->getJson($this->url())->assertJsonPath('data.today.status', 'present')->assertJsonPath('data.today.points', 2)->assertJsonPath('data.today.text', 'Today · Present · 2');
    }

    public function test_student_without_entries_gets_zeroes_and_no_data(): void
    {
        $empty = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Emery Nodata']);

        $data = $this->getJson($this->url($empty))->assertOk()->json('data');

        $this->assertSame(0, $data['stats']['total_points']);
        $this->assertSame('No data', $data['stats']['average_text']);
        $this->assertFalse($data['stats']['has_data']);
        $this->assertSame([], $data['log']);
        $this->assertFalse($data['chart']['enough']);
    }

    public function test_archived_student_without_entries_in_the_period_still_loads(): void
    {
        $old = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Quinn Gone', 'archived_at' => '2026-09-30 12:00:00']);

        $this->getJson($this->url($old))->assertOk()->assertJsonPath('data.student.archived', true)->assertJsonPath('data.stats.total_points', 0);
    }

    public function test_student_of_another_class_is_a_404_and_unknown_class_too(): void
    {
        $other = SchoolClass::factory()->create(['name' => 'OTHER 1']);
        $stranger = Student::factory()->create(['school_class_id' => $other->id, 'display_name' => 'Stranger Danger']);
        StudentNote::factory()->create(['school_class_id' => $other->id, 'student_id' => $stranger->id, 'note_date' => '2026-10-20', 'body' => 'Other class secret.']);

        $this->getJson($this->url($stranger))->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->getJson($this->url($stranger, '', $other))->assertOk()->assertJsonPath('data.student.name', 'Stranger Danger');
        $this->getJson('/api/classes/999999/students/'.$this->alex->id.'/semester')->assertStatus(404);
    }

    public function test_entries_of_other_classes_never_leak_into_the_numbers(): void
    {
        $other = SchoolClass::factory()->create(['name' => 'OTHER 1', 'semester_start' => '2026-09-08', 'semester_end' => '2027-01-29']);
        $twin = Student::factory()->create(['school_class_id' => $other->id, 'display_name' => 'Alex Rivera']);
        ParticipationEntry::factory()->create(['school_class_id' => $other->id, 'student_id' => $twin->id, 'work_date' => '2026-10-21', 'status' => 'present', 'points' => 50, 'restore_points' => null]);

        $this->getJson($this->url())->assertJsonPath('data.stats.total_points', 12);
    }

    public function test_guests_get_a_401_json_error(): void
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $this->getJson($this->url())->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }
}
