<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeekApiTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    private Student $alex;

    private Student $robin;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 10:00', 'America/Toronto'));
        $this->class = SchoolClass::factory()->create(['name' => 'HNL 2O']);
        $this->alex = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Alex Rivera']);
        $this->robin = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Robin Sky']);
        $this->record($this->alex, '2026-10-19', 'present', 3);
        $this->record($this->alex, '2026-10-21', 'absent', null);
        $this->record($this->robin, '2026-10-19', 'present', 1);
        $this->record($this->robin, '2026-10-12', 'present', 1);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function record(Student $student, string $date, string $status, ?int $points): void
    {
        ParticipationEntry::factory()->create([
            'school_class_id' => $student->school_class_id, 'student_id' => $student->id,
            'work_date' => $date, 'status' => $status, 'points' => $points, 'restore_points' => null,
        ]);
    }

    private function url(string $monday = '2026-10-19', ?SchoolClass $class = null): string
    {
        return '/api/classes/'.($class ?? $this->class)->id.'/weeks/'.$monday;
    }

    public function test_returns_the_recomputed_week_under_data(): void
    {
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.monday', '2026-10-19')
            ->assertJsonPath('data.range_label', 'Oct 19 – Oct 23, 2026')
            ->assertJsonPath('data.has_data', true)
            ->assertJsonPath('data.kpis.total_points.value', 4)
            ->assertJsonPath('data.kpis.average.value', '2.00')
            ->assertJsonPath('data.kpis.attendance.rate_text', '67%')
            ->assertJsonPath('data.kpis.attendance.detail', '2 of 3 recorded student-sessions')
            ->assertJsonPath('data.kpis.peak.weekday', 'Monday')
            ->assertJsonPath('data.kpis.average.delta.text', '+1.00')
            ->assertJsonPath('data.kpis.average.delta.direction', 'up')
            ->assertJsonPath('data.footer.total_points', 4)
            ->assertJsonPath('data.footer.absences', 1)
            ->assertJsonCount(2, 'data.students')
            ->assertJsonPath('data.students.0.id', $this->alex->id)
            ->assertJsonPath('data.students.0.cells.0.status', 'present')
            ->assertJsonPath('data.students.0.cells.0.points', 3)
            ->assertJsonPath('data.students.0.cells.2.status', 'absent')
            ->assertJsonPath('data.students.0.cells.3.status', 'none')
            ->assertJsonPath('data.students.0.total_text', '3')
            ->assertJsonPath('data.students.0.absences_text', '1 Absence')
            ->assertJsonPath('data.students.0.average_sub', '3 pts / 1 d')
            ->assertJsonStructure(['data' => ['days' => [['date', 'weekday', 'label', 'editable', 'today']], 'kpis' => ['average' => ['chart' => ['width', 'height', 'path', 'dots', 'alt']], 'total_points' => ['chart' => ['bars', 'baseline_y', 'alt']]], 'summary_text']]);
    }

    public function test_the_payload_reflects_a_changed_day_and_omits_a_comparison_without_a_previous_week(): void
    {
        $this->getJson($this->url('2026-10-12'))->assertOk()->assertJsonPath('data.kpis.average.delta', null);

        $this->record($this->robin, '2026-10-22', 'present', 6);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.kpis.total_points.value', 10)->assertJsonPath('data.students.1.cells.3.points', 6);
    }

    public function test_a_week_without_entries_has_no_averages(): void
    {
        $this->getJson($this->url('2026-11-02'))
            ->assertOk()
            ->assertJsonPath('data.has_data', false)
            ->assertJsonPath('data.kpis.average.value', 'No data')
            ->assertJsonPath('data.kpis.average.has_data', false)
            ->assertJsonPath('data.kpis.attendance.rate_text', 'No data')
            ->assertJsonPath('data.kpis.peak', null)
            ->assertJsonPath('data.students.0.average', null)
            ->assertJsonPath('data.students.0.average_text', '—');
    }

    public function test_it_is_scoped_to_the_class_in_the_url(): void
    {
        $other = SchoolClass::factory()->create();
        $casey = Student::factory()->create(['school_class_id' => $other->id, 'display_name' => 'Casey Moon']);
        $this->record($casey, '2026-10-19', 'present', 50);

        $mine = $this->getJson($this->url())->assertOk();
        $this->assertSame(4, $mine->json('data.kpis.total_points.value'));
        $this->assertNotContains($casey->id, array_column($mine->json('data.students'), 'id'));

        $theirs = $this->getJson($this->url('2026-10-19', $other))->assertOk();
        $this->assertSame(50, $theirs->json('data.kpis.total_points.value'));
        $this->assertSame([$casey->id], array_column($theirs->json('data.students'), 'id'));
    }

    public function test_unknown_class_and_bad_dates(): void
    {
        $this->getJson('/api/classes/9999/weeks/2026-10-19')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->getJson($this->url('2026-10-21'))->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->getJson($this->url('2026-02-30'))->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->getJson('/api/classes/'.$this->class->id.'/weeks/not-a-date')->assertStatus(404);
    }

    public function test_guests_get_a_401_json_error(): void
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $this->getJson($this->url())->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }
}
