<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use App\Services\ReportData;
use App\Services\TodaySnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportDataTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    private Student $alex;

    private Student $jordan;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 10:00', 'America/Toronto'));
        $this->class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'semester_start' => '2026-09-08', 'semester_end' => '2027-01-29']);
        $this->alex = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Alex Rivera', 'preferred_name' => 'Ali']);
        $this->jordan = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Jordan Lee']);
        $this->entry($this->alex, '2026-10-12', 'present', 1);
        $this->entry($this->alex, '2026-10-19', 'present', 4);
        $this->entry($this->alex, '2026-10-20', 'absent', null);
        $this->entry($this->jordan, '2026-10-19', 'present', 2);
        $this->entry($this->jordan, '2026-10-21', 'present', 0);

        $other = SchoolClass::factory()->create();
        $stranger = Student::factory()->create(['school_class_id' => $other->id]);
        ParticipationEntry::factory()->create(['school_class_id' => $other->id, 'student_id' => $stranger->id, 'work_date' => '2026-10-19', 'points' => 50]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function entry(Student $student, string $date, string $status, ?int $points): void
    {
        ParticipationEntry::factory()->create([
            'school_class_id' => $this->class->id, 'student_id' => $student->id,
            'work_date' => $date, 'status' => $status, 'points' => $points,
        ]);
    }

    public function test_weekly_assembles_stats_rows_and_charts_scoped_to_the_class(): void
    {
        $week = app(ReportData::class)->weekly($this->class, '2026-10-19');

        $this->assertSame(['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23'], $week['days']);
        $this->assertSame(6, $week['stats']['class']['total_points']);
        $this->assertSame([6, 0, 0, 0, 0], $week['stats']['day_points']);
        $this->assertSame(['Alex Rivera', 'Jordan Lee'], array_column($week['students'], 'name'));
        $this->assertSame('Ali', $week['students'][0]['preferred_name']);
        $this->assertSame([['status' => 'present', 'points' => 4], ['status' => 'absent', 'points' => null], ['status' => 'none', 'points' => null]], array_slice($week['students'][0]['cells'], 0, 3));
        $this->assertSame(['status' => 'present', 'points' => 0], $week['students'][1]['cells'][2]);
        $this->assertSame(1.0, $week['stats']['comparison']['previous_average']);
        $this->assertSame(2, $week['stats']['attendance']['not_recorded'], '2 students x 3 elapsed days minus 4 recorded');
        $this->assertCount(5, $week['charts']['points']['bars']);
        $this->assertTrue($week['charts']['average']['has_data']);
    }

    public function test_weekly_keeps_archived_students_that_have_entries_only(): void
    {
        $gone = Student::factory()->archived()->create(['school_class_id' => $this->class->id, 'display_name' => 'Old Timer']);
        $ghost = Student::factory()->archived()->create(['school_class_id' => $this->class->id, 'display_name' => 'No Entries']);
        $this->entry($gone, '2026-10-20', 'present', 3);

        $week = app(ReportData::class)->weekly($this->class, '2026-10-19');

        $names = array_column($week['students'], 'name');
        $this->assertContains('Old Timer', $names);
        $this->assertNotContains($ghost->display_name, $names);
        $this->assertTrue($week['students'][2]['archived']);
    }

    public function test_periods_resolve_full_semester_and_configured_quarters(): void
    {
        $reports = app(ReportData::class);
        $this->assertSame(['full'], array_keys($reports->periods($this->class)));

        AcademicPeriod::factory()->create(['school_class_id' => $this->class->id, 'kind' => 'q1', 'starts_on' => '2026-09-08', 'ends_on' => '2026-10-16']);
        $periods = $reports->periods($this->class);
        $this->assertSame(['full', 'q1'], array_keys($periods));
        $this->assertSame('Q1 / Midterm', $periods['q1']['label']);

        $bare = SchoolClass::factory()->create();
        $this->assertNull($reports->periods($bare)['full']['from']);
        $student = Student::factory()->create(['school_class_id' => $bare->id]);
        ParticipationEntry::factory()->create(['school_class_id' => $bare->id, 'student_id' => $student->id, 'work_date' => '2026-10-05']);
        ParticipationEntry::factory()->create(['school_class_id' => $bare->id, 'student_id' => $student->id, 'work_date' => '2026-10-07']);
        $this->assertSame(['2026-10-05', '2026-10-07'], [$reports->periods($bare)['full']['from'], $reports->periods($bare)['full']['to']]);
    }

    public function test_semester_full_and_quarter_with_notes_and_comparison(): void
    {
        AcademicPeriod::factory()->create(['school_class_id' => $this->class->id, 'kind' => 'q1', 'starts_on' => '2026-09-08', 'ends_on' => '2026-10-16']);
        AcademicPeriod::factory()->q2()->create(['school_class_id' => $this->class->id, 'starts_on' => '2026-10-19', 'ends_on' => '2026-12-18']);
        StudentNote::factory()->create(['school_class_id' => $this->class->id, 'student_id' => $this->alex->id, 'note_date' => '2026-10-19', 'body' => 'first']);
        StudentNote::factory()->create(['school_class_id' => $this->class->id, 'student_id' => $this->alex->id, 'note_date' => '2026-10-20', 'body' => 'second']);
        $reports = app(ReportData::class);

        $full = $reports->semester($this->class, null);
        $this->assertSame('full', $full['selected']['key']);
        $this->assertNull($full['previous']);
        $this->assertNull($full['stats']['comparison']);
        $this->assertSame(7, $full['stats']['kpi']['total_points']);
        $this->assertSame(['Alex Rivera', 'Jordan Lee'], array_column($full['students'], 'name'));
        $this->assertSame(['second', 'first'], array_column($full['notes'][$this->alex->id], 'body'), 'notes newest first');

        $q2 = $reports->semester($this->class, 'q2');
        $this->assertSame('q1', $q2['previous']['key']);
        $this->assertSame(6, $q2['stats']['kpi']['total_points']);
        $this->assertSame(1.0, $q2['stats']['comparison']['previous_mean']);
        $this->assertSame(4.0, $q2['stats']['students'][$this->alex->id]['average']);
        $this->assertSame(3.0, $q2['stats']['students'][$this->alex->id]['trend']['delta']);
        $this->assertSame(['2026-10-20', '2026-10-19'], array_column($q2['stats']['log'][$this->alex->id], 'date'));

        $unknown = $reports->semester($this->class, 'nope');
        $this->assertSame('full', $unknown['selected']['key']);
    }

    public function test_semester_of_a_class_without_dates_or_entries_is_empty_not_an_error(): void
    {
        $empty = SchoolClass::factory()->create();

        $data = app(ReportData::class)->semester($empty, 'full');

        $this->assertFalse($data['stats']['has_data']);
        $this->assertSame([], $data['students']);
        $this->assertSame([], $data['stats']['weeks']);
    }

    public function test_roster_stats_and_cadence(): void
    {
        $reports = app(ReportData::class);
        $roster = $reports->roster($this->class);

        $this->assertSame(2.5, $roster[$this->alex->id]['average']);
        $this->assertSame('none', $roster[$this->alex->id]['today']);
        $this->assertSame('present', $roster[$this->jordan->id]['today']);
        $this->assertSame(0, $roster[$this->jordan->id]['today_points']);
        $this->assertSame(1, $roster[$this->alex->id]['absences']);

        $cadence = $reports->studentCadence($this->class, $this->alex);
        $this->assertSame(['2026-10-12', '2026-10-19'], array_slice(array_column($cadence, 'monday'), -2));
        $this->assertSame([1.0, 4.0], array_slice(array_column($cadence, 'average'), -2));
        $this->assertNull($cadence[0]['average']);
    }

    public function test_today_snapshot_counts_only_active_students_for_the_school_date(): void
    {
        $archived = Student::factory()->archived()->create(['school_class_id' => $this->class->id]);
        $this->entry($archived, '2026-10-21', 'absent', null);
        $this->entry($this->alex, '2026-10-21', 'absent', null);
        $snapshots = app(TodaySnapshot::class);

        $this->assertSame(
            ['date' => '2026-10-21', 'enrolled' => 2, 'present' => 1, 'absent' => 1, 'not_recorded' => 0],
            $snapshots->for($this->class),
        );
        $this->assertSame(['date' => '2026-10-21', 'enrolled' => 0, 'present' => 0, 'absent' => 0, 'not_recorded' => 0], $snapshots->for(null));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 10:00', 'America/Toronto')); // Saturday -> Friday 2026-10-23
        $this->assertSame(['date' => '2026-10-23', 'enrolled' => 2, 'present' => 0, 'absent' => 0, 'not_recorded' => 2], $snapshots->for($this->class));
    }

    public function test_layout_receives_the_today_snapshot_through_a_view_composer(): void
    {
        $this->entry($this->alex, '2026-10-21', 'present', 2);
        $view = View::make('components.layouts.app', ['currentClass' => $this->class, 'slot' => '']);
        $view->render();

        $this->assertSame(
            ['date' => '2026-10-21', 'enrolled' => 2, 'present' => 2, 'absent' => 0, 'not_recorded' => 0],
            $view->getData()['todaySnapshot'],
        );
    }

    public function test_weekly_quick_edit_of_a_past_day_uses_the_day_api_and_undo_restores_it(): void
    {
        $this->actingAs(User::factory()->create());
        $day = '/api/classes/'.$this->class->id.'/days/2026-10-19/operations';
        $op = fn (string $kind, ?int $student = null) => ['op_id' => (string) Str::uuid(), 'kind' => $kind] + ($student === null ? [] : ['student_id' => $student]);

        // Monday of the shown week (today is Wednesday): Jordan had 2 points; a single-student increment changes only that row.
        $this->postJson($day, $op('increment', $this->jordan->id))->assertOk()
            ->assertJsonPath('data.entries.1.points', 3)
            ->assertJsonPath('data.can_undo', true);
        $this->assertSame(4, $this->entryPoints($this->alex, '2026-10-19'));

        // Mark absent from the popover, then undo it: the exact previous entry (3 points) is back.
        $this->postJson($day, $op('absent_on', $this->jordan->id))->assertOk()->assertJsonPath('data.entries.1.status', 'absent');
        $this->postJson($day, $op('undo'))->assertOk()
            ->assertJsonPath('data.entries.1.status', 'present')
            ->assertJsonPath('data.entries.1.points', 3);
        $this->postJson($day, $op('undo'))->assertOk()->assertJsonPath('data.entries.1.points', 2);
        $this->postJson($day, $op('undo'))->assertStatus(409)->assertJsonPath('error.code', 'nothing_to_undo');

        $this->assertSame(2, $this->entryPoints($this->jordan, '2026-10-19'));
        $this->assertSame(4, $this->entryPoints($this->alex, '2026-10-19'));
    }

    private function entryPoints(Student $student, string $date): ?int
    {
        return ParticipationEntry::where('student_id', $student->id)->where('work_date', $date)->value('points');
    }
}
