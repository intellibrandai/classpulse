<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\SchoolCalendar;
use App\Support\WeeklyStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Weekly Matrix page: KPI numbers, definitions, counters, hooks for search/sort/editor, separate states and the print sheet.
 * Known week 2026-10-19 (see setUp): 3 active students, 1 archived with one entry, "today" is Monday 2026-10-26.
 */
class WeeklyMatrixPageTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 10:00', 'America/Toronto'));

        $this->class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'title' => 'Grade 10 Food and Nutrition', 'period_label' => 'Semester 1']);
        $alex = $this->student('Alex Rivera', 'S-1001');
        $this->student('Jordan Lee');
        $robin = $this->student('Robin Sky');
        $morgan = $this->student('Morgan Diaz', null, '2026-10-24 12:00:00');

        $this->entry($alex, '2026-10-19', 'present', 3);
        $this->entry($alex, '2026-10-20', 'present', 0);
        $this->entry($alex, '2026-10-21', 'absent', null);
        $this->entry($alex, '2026-10-23', 'present', 5);
        $this->entry($robin, '2026-10-19', 'present', 1);
        $this->entry($robin, '2026-10-20', 'present', 2);
        $this->entry($morgan, '2026-10-21', 'present', 4);

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function student(string $name, ?string $number = null, ?string $archivedAt = null, ?SchoolClass $class = null): Student
    {
        return Student::factory()->create([
            'school_class_id' => ($class ?? $this->class)->id,
            'display_name' => $name,
            'student_number' => $number,
            'archived_at' => $archivedAt,
        ]);
    }

    private function entry(Student $student, string $date, string $status, ?int $points): void
    {
        ParticipationEntry::factory()->create([
            'school_class_id' => $student->school_class_id,
            'student_id' => $student->id,
            'work_date' => $date,
            'status' => $status,
            'points' => $points,
            'restore_points' => null,
        ]);
    }

    private function page(string $week = '2026-10-19', ?SchoolClass $class = null)
    {
        return $this->get('/weekly?class='.($class ?? $this->class)->id.'&week='.$week);
    }

    public function test_kpis_come_from_the_known_week(): void
    {
        $response = $this->page()->assertOk();

        // 15 points over 6 present student-days = 2.50; 6 present of 7 recorded = 86%; 15 possible - 6 recorded by active = 9.
        $response->assertSee('data-kpi-value="average">2.50<', false);
        $response->assertSee('data-kpi-value="total">15<', false);
        $response->assertSee('data-kpi-value="rate">86%<', false);
        $response->assertSee('6 of 7 recorded student-sessions');
        $response->assertSee('9 not recorded');
        $response->assertSee('data-peak-day>Friday<', false);
        $response->assertSee('5 points');
        $response->assertSee('1 present that day');
        $response->assertSee('Across 4 days with records');
        // No previous week data: no comparison badge is shown.
        $response->assertDontSee('vs previous week');
        $response->assertSee('<progress class="ui-progress ui-progress-success wm-progress" max="100" value="86"', false);
    }

    public function test_comparison_badge_appears_only_when_the_previous_week_has_present_days(): void
    {
        $alex = Student::where('display_name', 'Alex Rivera')->first();
        $this->entry($alex, '2026-10-12', 'present', 1);

        // previous average 1.00, current 2.50 -> +1.50
        $this->page()->assertOk()->assertSee('+1.50')->assertSee('vs previous week');
    }

    public function test_week_without_any_data_shows_no_data_everywhere_and_never_a_zero_average(): void
    {
        $response = $this->page('2026-11-02')->assertOk();

        $response->assertSee('data-kpi-value="average">No data<', false);
        $response->assertSee('data-kpi-value="rate">No data<', false);
        $response->assertSee('data-peak-day>No data<', false);
        $response->assertSee('No recorded student-sessions yet');
        $response->assertSee('Nothing recorded yet');
        $response->assertDontSee('vs previous week');
    }

    public function test_all_absent_week_has_a_zero_percent_rate_but_no_average_and_no_peak_day(): void
    {
        $robin = Student::where('display_name', 'Robin Sky')->first();
        $jordan = Student::where('display_name', 'Jordan Lee')->first();
        $this->entry($robin, '2026-10-05', 'absent', null);
        $this->entry($jordan, '2026-10-06', 'absent', null);

        $response = $this->page('2026-10-05')->assertOk();

        $response->assertSee('data-kpi-value="average">No data<', false);
        $response->assertSee('data-kpi-value="rate">0%<', false);
        $response->assertSee('0 of 2 recorded student-sessions');
        $response->assertSee('data-peak-day>No data<', false);
        $response->assertSee('data-foot="absences">2<', false);
    }

    public function test_definitions_are_written_in_plain_english(): void
    {
        $response = $this->page()->assertOk();

        $response->assertSee('Total points for the week divided by the student-days marked present. Absent and not-recorded days are left out.');
        $response->assertSee('participation points');
        $response->assertSee('Present sessions divided by recorded sessions (present plus absent).');
        $response->assertSee('The weekday with the most points this week. A tie goes to the earlier day.');
        $response->assertSee('Absent sessions (');
        $response->assertSee('not-recorded days are left out of every average');
        // Points are never called taps, clicks or logged taps.
        $response->assertDontSee('taps');
        $response->assertDontSee('clicks');
    }

    public function test_counter_search_and_sort_hooks_and_active_count(): void
    {
        $response = $this->page()->assertOk();

        // Active students only: Alex, Jordan and Robin (Morgan is archived).
        $response->assertSee('data-active-total="3"', false);
        $response->assertSee('Showing <strong>3</strong> active students', false);
        $response->assertSee('data-matrix-search', false);
        $response->assertSee('aria-keyshortcuts="/"', false);
        $response->assertSee('data-matrix-sort', false);
        foreach (['name-asc' => 'Name (A–Z)', 'name-desc' => 'Name (Z–A)', 'total-desc' => 'Weekly total (high to low)', 'avg-desc' => 'Weekly avg (high to low)', 'absences-desc' => 'Absences (most first)'] as $value => $label) {
            $response->assertSee('<option value="'.$value.'">'.$label.'</option>', false);
        }
        // Without JavaScript every row is there in name order (server order), each row carries its sort keys.
        $response->assertSeeInOrder(['Alex Rivera', 'Jordan Lee', 'Morgan Diaz', 'Robin Sky']);
        $response->assertSee('data-total="8"', false);
        $response->assertSee('data-absences="1"', false);
        $response->assertSee('data-name="alex rivera"', false);
    }

    public function test_row_numbers_and_footer(): void
    {
        $response = $this->page()->assertOk();

        // Alex: 3 + 0 + 5 = 8 points over 3 present days = 2.67, one absence. Jordan has nothing recorded.
        $response->assertSee('data-row-avg>2.67<', false);
        $response->assertSee('8 pts / 3 d');
        $response->assertSee('1 Absence');
        $response->assertSee('Weekly Total');
        $response->assertSee('Weekly Avg');
        $response->assertSee('Present days only');
        $response->assertSee('data-row-total>—<', false);
        $response->assertSee('data-foot="total">15<', false);
        $response->assertSee('data-foot="average">2.50<', false);
        $response->assertSee('data-foot="absences">1<', false);
        $response->assertSee('ID: S-1001');
    }

    public function test_not_recorded_zero_and_absent_stay_three_different_states(): void
    {
        $response = $this->page()->assertOk();

        $response->assertSee('data-status="none"', false);
        $response->assertSee('data-status="present" data-points="0"', false);
        $response->assertSee('data-status="absent"', false);
        $response->assertSee('Alex Rivera, Tue Oct 20: Present, 0 points. Open editor');
        $response->assertSee('Alex Rivera, Wed Oct 21: Absent. Open editor');
        $response->assertSee('Alex Rivera, Thu Oct 22: Not recorded. Open editor');
        $response->assertSee('aria-label="Legend"', false);
        $response->assertSee('Not recorded');
    }

    public function test_future_days_and_archived_students_are_not_editable(): void
    {
        // Today is Monday 2026-10-26: Tuesday onwards are in the future.
        $future = $this->page('2026-10-26')->assertOk();
        $future->assertSee('Future days cannot be edited');
        $future->assertSee('Alex Rivera, Tue Oct 27: Not recorded. Future days cannot be edited');
        $future->assertDontSee('Alex Rivera, Mon Oct 26: Not recorded. Future days cannot be edited');

        $past = $this->page()->assertOk();
        $past->assertSee('Morgan Diaz, Wed Oct 21: Present, 4 points. Archived students are read-only');
        $past->assertSee('Alex Rivera, Mon Oct 19: Present, 3 points. Open editor');
    }

    public function test_quick_editor_markup_is_accessible_and_complete(): void
    {
        $response = $this->page()->assertOk();

        $response->assertSee('role="dialog" aria-modal="true" aria-labelledby="cell-editor-title"', false);
        foreach (['decrement', 'increment', 'set_points', 'set_zero', 'absent_on', 'absent_off', 'clear'] as $action) {
            $response->assertSee('data-editor-action="'.$action.'"', false);
        }
        $response->assertSee('min="0" max="99"', false);
        $response->assertSee('aria-label="Close editor"', false);
        $response->assertSee('id="undo-btn"', false);
        $response->assertSee('id="save-pill"', false);
        $response->assertSee('js/weekly-lib.js', false);
        $response->assertSee('js/weekly.js', false);
        $response->assertSee('css/weekly.css', false);
    }

    public function test_toolbar_navigator_class_chip_and_actions(): void
    {
        $response = $this->page()->assertOk();

        // ISO week number when the class has no semester dates.
        $response->assertSee('Week 43: Oct 19 – Oct 23, 2026');
        $response->assertSee('Semester 1 · Calendar week');
        $response->assertSee('HNL 2O');
        $response->assertSee('Grade 10 Food and Nutrition');
        $response->assertSee('Copy Summary');
        $response->assertSee('Export CSV');
        $response->assertSee('Print Weekly Sheet');
        $response->assertSee('href="'.e(url('/export/weekly.csv?class='.$this->class->id.'&week=2026-10-19')).'"', false);
        $response->assertSee('href="'.e(url('/weekly?class='.$this->class->id.'&week=2026-10-12')).'"', false);
        $response->assertSee('href="'.e(url('/weekly?class='.$this->class->id.'&week=2026-10-26')).'"', false);
        $response->assertSee('This week');
    }

    public function test_week_number_counts_from_the_semester_start_when_the_class_has_one(): void
    {
        $this->class->update(['semester_start' => '2026-09-08', 'semester_end' => '2027-01-29']);

        // Week of Sep 7 is week 1, so Oct 19 is week 7.
        $this->page()->assertOk()->assertSee('Week 7: Oct 19 – Oct 23, 2026')->assertSee('Semester 1 · Semester week');
    }

    public function test_print_header_and_sheet_hooks(): void
    {
        $response = $this->page()->assertOk();

        $response->assertSee('class="print-header"', false);
        $response->assertSee('Weekly Participation Sheet');
        $response->assertSee('Week 43: Oct 19 – Oct 23, 2026');
        $response->assertSee('Printed on');
        $response->assertSee('data-print-week', false);
        $response->assertSee('data-print-summary', false);
        // The KPI row printed as text.
        $response->assertSee('<li>Class weekly average: 2.50 points per present day</li>', false);
        $response->assertSee('<li>Total points: 15</li>', false);
        $response->assertSee('Legend: A = absent');
        $response->assertSee('ui-kpi-grid wm-kpis no-print', false);
    }

    public function test_copy_summary_source_is_the_weekly_stats_summary_text(): void
    {
        $response = $this->page()->assertOk();

        $calendar = new SchoolCalendar;
        $days = $calendar->weekDays('2026-10-19');
        $rows = ParticipationEntry::query()->toBase()->get(['student_id', 'work_date', 'status', 'points'])
            ->map(fn ($e) => ['student_id' => (int) $e->student_id, 'work_date' => substr((string) $e->work_date, 0, 10), 'status' => (string) $e->status, 'points' => $e->points === null ? null : (int) $e->points])->all();
        $active = Student::whereNull('archived_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $stats = new WeeklyStats;
        $expected = $stats->summaryText('HNL 2O', 'Oct 19 – Oct 23, 2026', $stats->compute($days, $rows, $active, [], $calendar->today()));

        $response->assertSee('data-summary-source', false);
        $response->assertSee(e($expected), false);
        $this->assertStringContainsString('Recorded attendance: 86% (6 of 7 recorded student-sessions present; 9 not recorded)', $expected);
        $this->assertStringContainsString('Peak day: Friday (5 points)', $expected);
    }

    public function test_class_without_students_shows_an_empty_state(): void
    {
        $empty = SchoolClass::factory()->create(['name' => 'EMPTY 1X']);

        $this->page('2026-10-19', $empty)->assertOk()->assertSee('No students in this class')->assertSee('No data');
    }

    public function test_other_classes_data_never_leaks_into_the_page(): void
    {
        $other = SchoolClass::factory()->create(['name' => 'HNC 3C']);
        $casey = $this->student('Casey Moon', null, null, $other);
        $this->entry($casey, '2026-10-19', 'present', 40);

        $this->page()->assertOk()->assertDontSee('Casey Moon')->assertSee('data-kpi-value="total">15<', false);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $this->get('/weekly')->assertRedirect('/login');
    }
}
