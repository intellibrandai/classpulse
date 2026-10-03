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

/**
 * Semester Analytics page: toolbar, KPI numbers per period, master table hooks, comments dialog, print hooks, CSV per period.
 */
class SemesterAnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    private Student $alex;

    private Student $jordan;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 10:00', 'America/Toronto'));
        $this->class = SchoolClass::factory()->create([
            'name' => 'HNL 2O', 'title' => 'Nutrition & Health', 'semester_start' => '2026-09-08', 'semester_end' => '2027-01-29',
        ]);
        $this->actingAs(User::factory()->create());
        $this->alex = $this->student('Alex Rivera', 'S-1001');
        $this->jordan = $this->student('Jordan Lee');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function student(string $name, ?string $number = null, ?SchoolClass $class = null): Student
    {
        return Student::factory()->create(['school_class_id' => ($class ?? $this->class)->id, 'display_name' => $name, 'student_number' => $number]);
    }

    private function entry(Student $student, string $date, string $status, ?int $points): void
    {
        ParticipationEntry::factory()->create([
            'school_class_id' => $student->school_class_id, 'student_id' => $student->id,
            'work_date' => $date, 'status' => $status, 'points' => $points, 'restore_points' => null,
        ]);
    }

    /** Q1 (2026-09-08..2026-10-02) and Q2 (2026-10-05..2026-10-30) with known rows in both. */
    private function withPeriods(): void
    {
        $this->class->academicPeriods()->create(['kind' => 'q1', 'label' => 'Q1 / Midterm', 'starts_on' => '2026-09-08', 'ends_on' => '2026-10-02']);
        $this->class->academicPeriods()->create(['kind' => 'q2', 'label' => 'Q2 / Finals', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-30']);
        $this->entry($this->alex, '2026-09-14', 'present', 3);
        $this->entry($this->alex, '2026-09-15', 'present', 0);
        $this->entry($this->alex, '2026-09-16', 'absent', null);
        $this->entry($this->jordan, '2026-09-14', 'present', 2);
        $this->entry($this->alex, '2026-10-19', 'present', 5);
        $this->entry($this->alex, '2026-10-20', 'present', 4);
        $this->entry($this->jordan, '2026-10-19', 'present', 1);
        $this->entry($this->jordan, '2026-10-20', 'absent', null);
    }

    /**
     * @return array<string, mixed>
     */
    private function semesterView(string $query = ''): array
    {
        $response = $this->get('/semester?class='.$this->class->id.$query);
        $response->assertOk();

        return $response->viewData('semester');
    }

    public function test_full_semester_kpis_come_from_the_recorded_rows(): void
    {
        $this->withPeriods();

        $v = $this->semesterView();

        $this->assertSame('full', $v['period']['key']);
        $this->assertSame(15, $v['kpis']['total_points']['value']);
        $this->assertSame('Across 6 present days', $v['kpis']['total_points']['sub']);
        $this->assertSame('2.50', $v['kpis']['mean']['value']);
        $this->assertNull($v['kpis']['mean']['delta']);
        $this->assertSame(5, $v['kpis']['coverage']['value']);
        $this->assertSame('of 35 school days', $v['kpis']['coverage']['unit']);
        $this->assertSame('14% of elapsed school days have records', $v['kpis']['coverage']['sub']);
        $this->assertSame('75%', $v['kpis']['attendance']['rate_text']);
        $this->assertSame('2 absences recorded', $v['kpis']['attendance']['absences_text']);
        // 2 active students x 35 school days = 70 possible, 8 recorded.
        $this->assertSame('62 not recorded', $v['kpis']['attendance']['not_recorded_text']);
        $this->assertFalse($v['has_trend']);
        $this->assertSame(2, $v['enrolled']);
    }

    public function test_q1_shows_only_its_own_rows_and_no_comparison(): void
    {
        $this->withPeriods();

        $v = $this->semesterView('&period=q1');

        $this->assertSame('q1', $v['period']['key']);
        $this->assertSame('Q1 / Midterm', $v['period']['label']);
        $this->assertSame(5, $v['kpis']['total_points']['value']);
        $this->assertSame('1.67', $v['kpis']['mean']['value']);
        $this->assertSame(3, $v['kpis']['coverage']['value']);
        $this->assertSame('of 19 school days', $v['kpis']['coverage']['unit']);
        $this->assertSame('1 absence recorded', $v['kpis']['attendance']['absences_text']);
        $this->assertNull($v['kpis']['mean']['delta']);
        $this->assertNull($v['kpis']['attendance']['delta']);
        $this->assertNull($v['trend_header']);
    }

    public function test_q2_compares_with_q1_only_when_q1_has_a_present_day(): void
    {
        $this->withPeriods();

        $v = $this->semesterView('&period=q2');

        $this->assertSame(10, $v['kpis']['total_points']['value']);
        $this->assertSame('3.33', $v['kpis']['mean']['value']);
        $this->assertSame(['text' => '+1.67', 'direction' => 'up', 'label' => 'vs Q1 / Midterm'], $v['kpis']['mean']['delta']);
        $this->assertTrue($v['has_trend']);
        $this->assertSame('Trend vs Q1 / Midterm', $v['trend_header']);
        $byName = array_column($v['rows'], null, 'name');
        $this->assertSame(['text' => '+3.00', 'direction' => 'up'], $byName['Alex Rivera']['trend']);
        $this->assertSame(['text' => '-1.00', 'direction' => 'down'], $byName['Jordan Lee']['trend']);

        $html = $this->get('/semester?class='.$this->class->id.'&period=q2')->getContent();
        $this->assertStringContainsString('Trend vs Q1 / Midterm', $html);
        $this->assertStringContainsString('vs Q1 / Midterm', $html);
    }

    public function test_q2_has_no_comparison_when_q1_has_no_present_day(): void
    {
        $this->class->academicPeriods()->create(['kind' => 'q1', 'label' => 'Q1 / Midterm', 'starts_on' => '2026-09-08', 'ends_on' => '2026-10-02']);
        $this->class->academicPeriods()->create(['kind' => 'q2', 'label' => 'Q2 / Finals', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-30']);
        $this->entry($this->alex, '2026-09-14', 'absent', null);
        $this->entry($this->alex, '2026-10-19', 'present', 5);

        $v = $this->semesterView('&period=q2');

        $this->assertNull($v['kpis']['mean']['delta']);
        $this->assertNull($v['kpis']['attendance']['delta']);
        $this->assertFalse($v['has_trend']);
        $this->assertNull($v['trend_header']);
        $this->get('/semester?class='.$this->class->id.'&period=q2')->assertDontSee('Trend vs');
    }

    public function test_period_selector_lists_only_configured_periods_with_link_to_settings(): void
    {
        $settings = url('/roster?class='.$this->class->id.'#periods');

        $response = $this->get('/semester?class='.$this->class->id);
        $response->assertSee('Full Semester');
        $response->assertDontSee('Q1 / Midterm');
        $response->assertSee('Q1 and Q2 are not set for this class.');
        $response->assertSee('href="'.$settings.'"', false);
        $response->assertSee('Set period dates');
        $response->assertDontSee('Edit period dates');

        $this->withPeriods();
        $response = $this->get('/semester?class='.$this->class->id.'&period=q1');
        $response->assertSee('Q1 / Midterm');
        $response->assertSee('Q2 / Finals');
        $response->assertSee('Edit period dates');
        $response->assertDontSee('Q1 and Q2 are not set for this class.');
        $response->assertSee('Sep 8, 2026 – Oct 2, 2026');
        $response->assertSee('href="'.url('/semester?class='.$this->class->id.'&amp;period=q2').'"', false);
        $response->assertSee('href="'.url('/semester?class='.$this->class->id).'"', false);
    }

    public function test_only_one_quarter_configured_shows_only_that_quarter(): void
    {
        $this->class->academicPeriods()->create(['kind' => 'q2', 'label' => 'Q2 / Finals', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-30']);

        $v = $this->semesterView();

        $this->assertSame(['full', 'q2'], array_column($v['periods'], 'key'));
    }

    public function test_unknown_or_unconfigured_period_falls_back_to_full_semester(): void
    {
        $this->withPeriods();
        $this->class->academicPeriods()->where('kind', 'q2')->delete();

        $this->assertSame('full', $this->semesterView('&period=nonsense')['period']['key']);
        $this->assertSame('full', $this->semesterView('&period=q2')['period']['key']);
        $this->assertSame('full', $this->semesterView('&period[]=q1')['period']['key']);
        $this->assertSame('full', $this->semesterView('&period=')['period']['key']);
    }

    public function test_class_without_semester_dates_uses_first_to_last_recorded_date(): void
    {
        $bare = SchoolClass::factory()->create(['name' => 'BARE 1', 'semester_start' => null, 'semester_end' => null]);
        $student = $this->student('Sam Nguyen', null, $bare);

        $empty = $this->get('/semester?class='.$bare->id);
        $empty->assertOk()->assertSee('No dates recorded yet')->assertSee('No data: nothing has been recorded in Full Semester yet.');
        $this->assertSame(0, $empty->viewData('semester')['kpis']['coverage']['value']);
        $this->assertSame('No school days have passed in this period yet', $empty->viewData('semester')['kpis']['coverage']['sub']);

        $this->entry($student, '2026-10-12', 'present', 4);
        $this->entry($student, '2026-10-14', 'present', 2);
        $v = $this->get('/semester?class='.$bare->id)->viewData('semester');
        $this->assertSame('Oct 12, 2026 – Oct 14, 2026', $v['period']['range_label']);
        $this->assertSame(6, $v['kpis']['total_points']['value']);
        $this->assertSame('3.00', $v['kpis']['mean']['value']);
    }

    public function test_class_with_students_but_no_entries_shows_no_data_everywhere(): void
    {
        $response = $this->get('/semester?class='.$this->class->id);

        $response->assertOk()->assertSee('No data: nothing has been recorded in Full Semester yet.');
        $v = $response->viewData('semester');
        $this->assertFalse($v['has_data']);
        $this->assertSame('No data', $v['kpis']['mean']['value']);
        $this->assertSame('No data', $v['kpis']['attendance']['rate_text']);
        $this->assertSame(0, $v['kpis']['total_points']['value']);
        $this->assertSame('Nothing recorded yet', $v['kpis']['total_points']['sub']);
        foreach ($v['rows'] as $row) {
            $this->assertSame('No data', $row['average_text']);
            $this->assertNull($row['trend']);
        }
    }

    public function test_all_absent_class_has_zero_points_no_mean_and_zero_attendance(): void
    {
        $this->entry($this->alex, '2026-10-19', 'absent', null);
        $this->entry($this->jordan, '2026-10-19', 'absent', null);

        $v = $this->semesterView();

        $this->assertTrue($v['has_data']);
        $this->assertSame(0, $v['kpis']['total_points']['value']);
        $this->assertSame('No data', $v['kpis']['mean']['value']);
        $this->assertFalse($v['kpis']['mean']['has_data']);
        $this->assertSame('0%', $v['kpis']['attendance']['rate_text']);
        $this->assertSame('2 absences recorded', $v['kpis']['attendance']['absences_text']);
        $this->assertSame(1, $v['kpis']['coverage']['value']);
    }

    public function test_class_without_students_shows_the_empty_state(): void
    {
        $empty = SchoolClass::factory()->create(['name' => 'EMPTY 1']);

        $this->get('/semester?class='.$empty->id)
            ->assertOk()
            ->assertSee('No students in this class')
            ->assertDontSee('data-inspector', false);
    }

    public function test_kpis_do_not_invent_targets_rankings_or_grade_percentages(): void
    {
        $this->withPeriods();

        $html = $this->get('/semester?class='.$this->class->id)->getContent();

        $this->assertStringNotContainsString('Target baseline', $html);
        $this->assertStringNotContainsString('Top 15%', $html);
        $this->assertStringNotContainsString('cohort', $html);
        // Every metric carries a plain-English definition.
        foreach (['Total points', 'Mean per present day', 'Session coverage', 'Attendance health'] as $label) {
            $this->assertStringContainsString('aria-label="About '.$label.'"', $html);
        }
        $this->assertStringContainsString('Absent and not-recorded days are left out', $html);
    }

    public function test_master_table_has_search_sort_paging_and_inspect_hooks(): void
    {
        $this->withPeriods();

        $response = $this->get('/semester?class='.$this->class->id);

        $response->assertSee('Master Cumulative Roster');
        $response->assertSee('2 Enrolled');
        $response->assertSee('data-search', false);
        $response->assertSee('aria-keyshortcuts="/"', false);
        $response->assertSee('data-page-size="25"', false);
        foreach (['name-asc', 'total-desc', 'avg-desc', 'absences-desc', 'present-desc'] as $value) {
            $response->assertSee('<option value="'.$value.'">', false);
        }
        $response->assertSee('data-inspect', false);
        $response->assertSee('aria-label="Inspect Alex Rivera"', false);
        $response->assertSee('href="'.url('/students/'.$this->alex->id).'"', false);
        $response->assertSee('Showing 2 of 2 students');
        // No rank column until a metric sort is chosen: the header is hidden and empty.
        $response->assertSee('data-rank-head hidden', false);
        $response->assertDontSee('>Rank by', false);
        $response->assertSee('data-total="12"', false);
        $response->assertSee('data-total="3"', false);
        $response->assertSee('data-avg="3"', false);
        $response->assertSee('data-avg="1.5"', false);
        $response->assertSee('Present / recorded days');
        $response->assertSee('Semester avg');
    }

    public function test_archived_students_with_entries_stay_listed_but_are_not_enrolled(): void
    {
        $old = $this->student('Morgan Diaz');
        $old->update(['archived_at' => '2026-10-24 12:00:00']);
        $this->entry($old, '2026-10-21', 'present', 4);
        $this->student('Quinn Gone')->update(['archived_at' => '2026-09-30 12:00:00']);

        $response = $this->get('/semester?class='.$this->class->id);

        $response->assertSee('Morgan Diaz')->assertSee('Archived')->assertDontSee('Quinn Gone');
        $this->assertSame(2, $response->viewData('semester')['enrolled']);
    }

    public function test_display_shows_preferred_name_and_number(): void
    {
        $this->jordan->update(['preferred_name' => 'Jo']);

        $this->get('/semester?class='.$this->class->id)
            ->assertSee('Preferred: Jo')
            ->assertSee('ID: S-1001');
    }

    public function test_comments_dialog_lists_students_with_notes_inside_the_selected_period_only(): void
    {
        $this->withPeriods();
        StudentNote::factory()->create(['school_class_id' => $this->class->id, 'student_id' => $this->alex->id, 'note_date' => '2026-09-15', 'body' => 'Q1 remark about lab work.']);
        StudentNote::factory()->create(['school_class_id' => $this->class->id, 'student_id' => $this->alex->id, 'note_date' => '2026-10-20', 'body' => 'Q2 remark about leadership.']);
        $other = SchoolClass::factory()->create(['name' => 'OTHER 1']);
        $stranger = $this->student('Stranger Danger', null, $other);
        StudentNote::factory()->create(['school_class_id' => $other->id, 'student_id' => $stranger->id, 'note_date' => '2026-10-20', 'body' => 'Other class secret.']);

        $q2 = $this->get('/semester?class='.$this->class->id.'&period=q2');
        $q2->assertSee('data-comments-dialog', false);
        $q2->assertSee('Report Card Comments');
        $q2->assertSee('saved automatically as a draft');
        $q2->assertSee('data-comment-reset', false);
        $q2->assertSee('data-comment-badge', false);
        $q2->assertSee('data-comment-api="'.url('/api/classes/'.$this->class->id.'/students/{student}/report-comments/q2').'"', false);
        $q2->assertSee('Q2 remark about leadership.');
        $q2->assertDontSee('Q1 remark about lab work.');
        $q2->assertDontSee('Other class secret.');
        $q2->assertDontSee('Stranger Danger');
        $q2->assertSee('data-comment-copy', false);
        $q2->assertSee('data-comments-copy-all', false);
        $q2->assertSee('aria-label="Copy comment for Alex Rivera"', false);
        $q2->assertSee('data-total="9"', false);
        $q2->assertSee('data-present="2"', false);

        $full = $this->get('/semester?class='.$this->class->id);
        $full->assertSee('Q1 remark about lab work.')->assertSee('Q2 remark about leadership.');

        $rows = array_column($q2->viewData('semester')['rows'], null, 'name');
        $this->assertSame(['2026-10-20'], array_column($rows['Alex Rivera']['notes'], 'date'));
        $this->assertSame([], $rows['Jordan Lee']['notes']);
    }

    public function test_print_hooks_cover_header_kpi_text_table_and_selected_student_log(): void
    {
        $this->withPeriods();

        $response = $this->get('/semester?class='.$this->class->id.'&period=q2');

        $response->assertSee('class="print-header"', false);
        $response->assertSee('Semester Summary');
        $response->assertSee('Printed on');
        $response->assertSee('HNL 2O');
        $response->assertSee('Q2 / Finals · Oct 5, 2026 – Oct 30, 2026');
        $response->assertSee('data-print-summary', false);
        $response->assertSee('Total points: 10');
        $response->assertSee('Mean per present day: 3.33 points');
        $response->assertSee('data-print-log', false);
        $response->assertSee('sem-print-kpis print-only', false);
        // The table has every student as a server-rendered row (paging only hides rows on screen).
        $this->assertSame(2, substr_count($response->getContent(), 'data-row'));
    }

    public function test_page_has_no_inline_scripts_styles_or_emoji(): void
    {
        $this->withPeriods();

        $html = $this->get('/semester?class='.$this->class->id.'&period=q2')->getContent();

        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html);
        $this->assertStringNotContainsString('<style', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $html);
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $html);
    }

    public function test_class_switch_control_lists_the_users_classes(): void
    {
        $other = SchoolClass::factory()->create(['name' => 'HLS 3O', 'title' => 'Living Skills']);

        $response = $this->get('/semester?class='.$this->class->id);

        $response->assertSee('data-class-menu', false);
        $response->assertDontSee('data-class-form', false);
        $response->assertSee('HNL 2O • Nutrition &amp; Health', false);
        $response->assertSee('?class='.$other->id.'"', false);
        $this->get('/semester?class='.$other->id)->assertOk()->assertSee('HLS 3O • Living Skills', false);
    }

    public function test_csv_for_q1_and_q2_honours_the_period_and_full_is_unchanged(): void
    {
        $this->withPeriods();
        $id = $this->class->id;

        $full = $this->get('/export/semester.csv?class='.$id);
        $header = '"Student","Student number","Total points","Present days recorded","Absences","Days recorded","Participation days","Average per present day"'."\n";
        $full->assertHeader('Content-Disposition', 'attachment; filename="classpulse-semester-HNL-2O-2026-09-08-to-2026-10-26-exported-2026-10-26.csv"');
        $this->assertSame(
            $header
            .'"Alex Rivera","S-1001",12,4,1,5,3,"3.00"'."\n"
            .'"Jordan Lee","",3,2,1,3,2,"1.50"'."\n",
            $full->getContent(),
        );

        $q1 = $this->get('/export/semester.csv?class='.$id.'&period=q1');
        $q1->assertHeader('Content-Disposition', 'attachment; filename="classpulse-semester-HNL-2O-2026-09-08-to-2026-10-02-exported-2026-10-26.csv"');
        $this->assertSame(
            $header
            .'"Alex Rivera","S-1001",3,2,1,3,1,"1.50"'."\n"
            .'"Jordan Lee","",2,1,0,1,1,"2.00"'."\n",
            $q1->getContent(),
        );

        $q2 = $this->get('/export/semester.csv?class='.$id.'&period=q2');
        $q2->assertHeader('Content-Disposition', 'attachment; filename="classpulse-semester-HNL-2O-2026-10-05-to-2026-10-26-exported-2026-10-26.csv"');
        $this->assertSame(
            $header
            .'"Alex Rivera","S-1001",9,2,0,2,2,"4.50"'."\n"
            .'"Jordan Lee","",1,1,1,2,1,"1.00"'."\n",
            $q2->getContent(),
        );
    }

    public function test_csv_ignores_unknown_or_unconfigured_periods(): void
    {
        $this->entry($this->alex, '2026-09-14', 'present', 3);
        $id = $this->class->id;
        $default = $this->get('/export/semester.csv?class='.$id)->getContent();

        $this->assertSame($default, $this->get('/export/semester.csv?class='.$id.'&period=full')->getContent());
        $this->assertSame($default, $this->get('/export/semester.csv?class='.$id.'&period=q1')->getContent());
        $this->assertSame($default, $this->get('/export/semester.csv?class='.$id.'&period=zzz')->getContent());
        $this->assertSame($default, $this->get('/export/semester.csv?class='.$id.'&period[]=q2')->getContent());
    }

    public function test_export_link_carries_the_selected_period(): void
    {
        $this->withPeriods();
        $id = $this->class->id;

        $this->get('/semester?class='.$id)->assertSee('href="'.url('/export/semester.csv?class='.$id).'"', false);
        $this->get('/semester?class='.$id.'&period=q2')->assertSee('href="'.url('/export/semester.csv?class='.$id.'&amp;period=q2').'"', false);
    }

    public function test_notes_saved_through_the_api_show_up_in_the_inspector_and_the_comments_dialog(): void
    {
        $this->withPeriods();
        $notes = '/api/classes/'.$this->class->id.'/students/'.$this->alex->id.'/notes/2026-10-20';
        $semester = '/api/classes/'.$this->class->id.'/students/'.$this->alex->id.'/semester?period=q2';

        $this->putJson($notes, ['body' => 'First draft remark.'])->assertOk();
        $this->getJson($semester)->assertOk()->assertJsonPath('data.notes.0.body', 'First draft remark.');

        $this->putJson($notes, ['body' => 'Edited remark.'])->assertOk();
        $this->getJson($semester)->assertJsonCount(1, 'data.notes')->assertJsonPath('data.notes.0.body', 'Edited remark.');
        $this->get('/semester?class='.$this->class->id.'&period=q2')->assertSee('Edited remark.')->assertDontSee('First draft remark.');

        $this->deleteJson($notes)->assertOk()->assertJsonPath('data.deleted', true);
        $this->getJson($semester)->assertJsonCount(0, 'data.notes');
        $this->get('/semester?class='.$this->class->id.'&period=q2')->assertDontSee('Edited remark.');
    }

    public function test_notes_persist_across_sessions_and_validation_errors_are_inline_json(): void
    {
        $notes = '/api/classes/'.$this->class->id.'/students/'.$this->alex->id.'/notes/2026-10-20';
        $this->putJson($notes, ['body' => 'Kept after logging out.'])->assertOk();

        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create());

        $this->get('/semester?class='.$this->class->id)->assertSee('Kept after logging out.');
        $this->putJson($notes, ['body' => str_repeat('x', 2001)])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation')
            ->assertJsonPath('error.message', 'A note can have at most 2000 characters.');
        $this->putJson('/api/classes/'.$this->class->id.'/students/'.$this->alex->id.'/notes/2026-02-30', ['body' => 'x'])
            ->assertStatus(422);
    }
}
