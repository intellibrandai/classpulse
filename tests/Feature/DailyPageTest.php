<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyPageTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 10:00', 'America/Toronto'));
        $this->actingAs(User::factory()->create());
        $this->class = SchoolClass::factory()->create(['name' => 'HNL 2O']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function student(string $name, ?string $status = null, ?int $points = null, ?int $restore = null): Student
    {
        $student = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => $name]);
        if ($status !== null) {
            ParticipationEntry::factory()->create([
                'school_class_id' => $this->class->id,
                'student_id' => $student->id,
                'work_date' => '2026-10-21',
                'status' => $status,
                'points' => $points,
                'restore_points' => $restore,
            ]);
        }

        return $student;
    }

    private function page(string $query = '')
    {
        return $this->get('/daily?class='.$this->class->id.$query);
    }

    /** Returns the HTML of one student's card. */
    private function card(string $html, Student $student): string
    {
        preg_match('/<article class="student-card" data-student-id="'.$student->id.'".*?<\/article>/s', $html, $m);
        $this->assertNotEmpty($m, 'card not found');

        return $m[0];
    }

    public function test_renders_one_card_per_active_student_and_the_date_text(): void
    {
        $active = $this->student('Alex Rivera');
        $archived = Student::factory()->archived()->create(['school_class_id' => $this->class->id, 'display_name' => 'Jordan Lee']);

        $response = $this->page()->assertOk()->assertSee('Wednesday, Oct 21, 2026');
        $html = $response->getContent();

        $this->assertStringContainsString('data-student-id="'.$active->id.'"', $html);
        $this->assertStringNotContainsString('data-student-id="'.$archived->id.'"', $html);
        $this->assertSame(1, substr_count($html, 'class="student-card"'));
        $this->assertStringContainsString('data-api="/api/classes/'.$this->class->id.'/days/2026-10-21"', $html);
        $this->assertStringContainsString('data-day-version="0"', $html);
    }

    public function test_card_states_not_recorded_zero_points_and_absent(): void
    {
        $none = $this->student('Alex Rivera');
        $zero = $this->student('Blair Ito', 'present', 0);
        $points = $this->student('Casey Lee', 'present', 4);
        $absent = $this->student('Dana Ng', 'absent', null, 2);

        $html = $this->page()->assertOk()->getContent();

        $noneCard = $this->card($html, $none);
        $this->assertStringContainsString('Not recorded', $noneCard);
        $this->assertStringContainsString('Record 0', $noneCard);
        $this->assertStringContainsString('data-status="none"', $noneCard);
        $this->assertStringContainsString('data-state="untouched"', $noneCard);
        $this->assertStringContainsString('data-action="absent_on"', $noneCard);

        $zeroCard = $this->card($html, $zero);
        $this->assertStringContainsString('Present · 0', $zeroCard);
        $this->assertStringNotContainsString('Record 0', $zeroCard);
        $this->assertStringContainsString('data-state="zero"', $zeroCard);

        $pointsCard = $this->card($html, $points);
        $this->assertStringContainsString('Present', $pointsCard);
        $this->assertStringNotContainsString('Present · 0', $pointsCard);
        $this->assertStringContainsString('>4<', $pointsCard);

        $absentCard = $this->card($html, $absent);
        $this->assertStringContainsString('Absent', $absentCard);
        $this->assertStringContainsString('data-action="absent_off"', $absentCard);
        $this->assertStringContainsString('Marked Absent (Tap to clear)', $absentCard);
        $this->assertStringContainsString('data-status="absent"', $absentCard);
        $this->assertStringContainsString('data-state="absent"', $absentCard);
    }

    public function test_absent_card_disables_point_buttons(): void
    {
        $absent = $this->student('Dana Ng', 'absent', null, 2);
        $present = $this->student('Alex Rivera', 'present', 1);

        $html = $this->page()->getContent();
        $absentCard = $this->card($html, $absent);
        $presentCard = $this->card($html, $present);

        $this->assertMatchesRegularExpression('/<button[^>]*data-action="decrement"[^>]*disabled/', $absentCard);
        $this->assertMatchesRegularExpression('/<button[^>]*data-action="increment"[^>]*disabled/', $absentCard);
        $this->assertStringContainsString('data-state="absent"', $absentCard);
        $this->assertStringContainsString('data-action="absent_off"', $absentCard);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*data-action="increment"[^>]*disabled/', $presentCard);
    }

    public function test_weekend_redirects_to_friday_and_future_to_today(): void
    {
        $this->student('Alex Rivera');
        $id = $this->class->id;

        $this->page('&date=2026-10-24')->assertRedirect('/daily?class='.$id.'&date=2026-10-23');
        $this->page('&date=2026-10-25')->assertRedirect('/daily?class='.$id.'&date=2026-10-23');
        $this->page('&date=2026-10-22')->assertRedirect('/daily?class='.$id.'&date=2026-10-21');
        $this->page('&date=2027-01-05')->assertRedirect('/daily?class='.$id.'&date=2026-10-21');
    }

    public function test_future_date_on_a_weekend_today_redirects_to_the_previous_friday(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 10:00', 'America/Toronto'));
        $this->student('Alex Rivera');

        $this->page('&date=2026-10-26')->assertRedirect('/daily?class='.$this->class->id.'&date=2026-10-23');
        $this->page()->assertOk()->assertSee('Friday, Oct 23, 2026');
    }

    public function test_navigation_links_and_disabled_next_on_today(): void
    {
        $this->student('Alex Rivera');
        $id = $this->class->id;

        $monday = $this->page('&date=2026-10-19')->assertOk()->getContent();
        $previous = preg_quote(e(url('/daily?class='.$id.'&date=2026-10-16')), '/');
        $next = preg_quote(e(url('/daily?class='.$id.'&date=2026-10-20')), '/');
        $this->assertMatchesRegularExpression('/<a[^>]*href="'.$previous.'"[^>]*aria-label="Previous school day"/', $monday);
        $this->assertMatchesRegularExpression('/<a[^>]*href="'.$next.'"[^>]*aria-label="Next school day"/', $monday);

        $today = $this->page('&date=2026-10-21')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<button[^>]*aria-label="Next school day"[^>]*disabled/', $today);
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*aria-label="Next school day"/', $today);
        $this->assertMatchesRegularExpression('/<a[^>]*href="'.preg_quote(e(url('/daily?class='.$id)), '/').'">Today<\/a>/', $today);
        $this->assertStringContainsString('aria-label="Previous school day"', $today);
    }

    public function test_labels_pill_summary_and_toolbar_hooks(): void
    {
        $this->student('Alex Rivera', 'present', 3);
        $this->student('Blair Ito', 'absent', null, null);
        $this->student('Casey Lee');

        $response = $this->page()->assertOk()
            ->assertSee('aria-label="Add one point to Alex Rivera"', false)
            ->assertSee('aria-label="Remove one point from Alex Rivera"', false)
            ->assertSee('aria-label="Mark Absent for Alex Rivera"', false)
            ->assertSee('id="save-pill"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('id="undo-btn"', false)
            ->assertSee('Undo last action')
            ->assertSee('data-dialog="reset-dialog"', false)
            ->assertSee('data-dialog="zero-dialog"', false)
            ->assertSee('id="student-search"', false)
            ->assertSee('id="search-count"', false)
            ->assertSee('data-filter="absent"', false)
            ->assertSee('Reset all entries for HNL 2O on Wednesday, Oct 21, 2026? This removes 2 recorded entries.', false);
        $this->assertStringContainsString('<span id="save-pill"', $response->getContent());
        $this->assertStringContainsString('data-summary="total">3<', $response->getContent());
    }

    public function test_summary_strip_has_mean_and_separate_present_absent_and_not_recorded_counts(): void
    {
        $this->student('Alex Rivera', 'present', 3);
        $this->student('Blair Ito', 'present', 0);
        $this->student('Casey Lee', 'absent', null, null);
        $this->student('Dana Ng');
        $this->student('Eli Park');

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString('data-summary="mean">1.50<', $html);
        $this->assertStringContainsString('data-summary="present">2<', $html);
        $this->assertStringContainsString('data-summary="absent">1<', $html);
        $this->assertStringContainsString('data-summary="none">2<', $html);
    }

    public function test_banner_shows_class_name_description_ratio_and_the_absent_and_not_recorded_split(): void
    {
        $this->class->update(['subject_description' => 'Grade 10 Health and Nutrition']);
        $this->student('Alex Rivera', 'present', 3);
        $this->student('Blair Ito', 'absent', null, null);
        $this->student('Casey Lee');
        $this->student('Dana Ng');

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString('class="banner-unit-title">HNL 2O<', $html);
        $this->assertStringContainsString('class="banner-unit-desc">Grade 10 Health and Nutrition<', $html);
        $this->assertStringContainsString('data-summary="present">1</span>/<span data-summary="active">4<', $html);
        $this->assertStringContainsString('data-summary="absent">1<', $html);
        $this->assertStringContainsString('data-summary="none">2<', $html);
        $this->assertStringContainsString('pts/student', $html);
    }

    public function test_banner_uses_a_neutral_line_when_the_class_has_no_description(): void
    {
        $this->class->update(['subject_description' => null]);
        $this->student('Alex Rivera');

        $this->page()->assertOk()
            ->assertSee('class="banner-unit-desc">Daily participation for this class.<', false);
    }

    public function test_mean_shows_no_data_without_present_students(): void
    {
        $this->student('Alex Rivera');

        $this->page()->assertOk()->assertSee('data-summary="mean">No data<', false);
    }

    public function test_brand_subtitle_and_no_stitch_demo_leftovers(): void
    {
        $this->student('Alex Rivera');

        $html = $this->page()->assertOk()->assertSee('Student Participation Tracker')->getContent();

        foreach (['Classroom Cadence', 'Cloud Sync', 'Fill Untouched', 'Untouched', '>PRO<', 'Seat 0', 'Period 2', 'Optimal Cadence', 'user-avatar'] as $leftover) {
            $this->assertStringNotContainsString($leftover, $html);
        }
        $this->assertStringNotContainsString('>Login<', $html);
    }

    public function test_three_states_stay_separate_and_minus_is_disabled_at_zero_and_not_recorded(): void
    {
        $none = $this->student('Alex Rivera');
        $zero = $this->student('Blair Ito', 'present', 0);
        $points = $this->student('Casey Lee', 'present', 2);
        $absent = $this->student('Dana Ng', 'absent', null, 2);
        $html = $this->page()->assertOk()->getContent();

        $noneCard = $this->card($html, $none);
        $this->assertStringContainsString('<span class="score-number">—</span>', $noneCard);
        $this->assertStringContainsString('Not recorded', $noneCard);
        $this->assertStringNotContainsString('Present', $noneCard);
        $this->assertStringNotContainsString('>0<', $noneCard);
        $this->assertMatchesRegularExpression('/<button[^>]*data-action="decrement"[^>]*disabled/', $noneCard);

        $zeroCard = $this->card($html, $zero);
        $this->assertStringContainsString('<span class="score-number">0</span>', $zeroCard);
        $this->assertStringContainsString('Present · 0', $zeroCard);
        $this->assertStringNotContainsString('Not recorded', $zeroCard);
        $this->assertMatchesRegularExpression('/<button[^>]*data-action="decrement"[^>]*disabled/', $zeroCard);

        $this->assertDoesNotMatchRegularExpression('/<button[^>]*data-action="decrement"[^>]*disabled/', $this->card($html, $points));

        $absentCard = $this->card($html, $absent);
        $this->assertStringContainsString('<span class="score-number">—</span>', $absentCard);
        $this->assertStringNotContainsString('Not recorded', $absentCard);
        $this->assertStringNotContainsString('Present ·', $absentCard);
    }

    public function test_side_panel_renders_the_current_week_from_the_database(): void
    {
        $student = $this->student('Alex Rivera', 'present', 3);
        // Week of 2026-10-19 (Mon) to 2026-10-23 (Fri); today is Wed 2026-10-21.
        foreach ([['2026-10-20', 'absent', null], ['2026-10-22', 'present', 5]] as [$date, $status, $pts]) {
            ParticipationEntry::factory()->create([
                'school_class_id' => $this->class->id,
                'student_id' => $student->id,
                'work_date' => $date,
                'status' => $status,
                'points' => $pts,
                'restore_points' => null,
            ]);
        }
        // An entry from the previous week must not leak into the panel.
        ParticipationEntry::factory()->create([
            'school_class_id' => $this->class->id,
            'student_id' => $student->id,
            'work_date' => '2026-10-16',
            'status' => 'present',
            'points' => 9,
            'restore_points' => null,
        ]);

        $html = $this->page()->assertOk()->getContent();
        preg_match('/<section class="inspector-student" data-panel-student="'.$student->id.'".*?<\/section>/s', $html, $m);
        $this->assertNotEmpty($m, 'panel not found');
        $panel = $m[0];

        $this->assertStringContainsString('Present · 3 pts today', $panel);
        $this->assertStringContainsString('Current week breakdown', $panel);
        foreach (['Mon, Oct 19', 'Tue, Oct 20', 'Wed, Oct 21', 'Thu, Oct 22', 'Fri, Oct 23'] as $label) {
            $this->assertStringContainsString($label, $panel);
        }
        $this->assertSame(5, substr_count($panel, 'class="mini-day-row"'));
        $this->assertStringContainsString('Upcoming', $panel);
        $this->assertStringContainsString('Absent', $panel);
        $this->assertStringContainsString('3 pts', $panel);
        $this->assertStringContainsString('5 pts', $panel);
        $this->assertStringContainsString('Not recorded', $panel);
        $this->assertStringNotContainsString('9 pts', $panel);
        $this->assertMatchesRegularExpression('/data-today="true"\s+data-day-row/', $panel);
        $this->assertStringContainsString('aria-controls="detail-panel"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
    }

    public function test_no_student_is_selected_by_default_and_the_panel_shows_the_empty_state(): void
    {
        $student = $this->student('Alex Rivera', 'present', 2);
        $this->student('Blair Ito');

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringNotContainsString('card-selected', $html);
        $this->assertStringContainsString('<div class="inspector-empty" data-panel-empty>', $html);
        $this->assertStringContainsString('Select a student', $html);
        $this->assertStringContainsString('Tap a card to see this week’s breakdown, the cadence trend and a session note.', $html);
        $this->assertSame(2, preg_match_all('/<section class="inspector-student"[^>]*\shidden>/', $html));
        // Panel content is server-rendered for every student, hidden until one is selected.
        $this->assertStringContainsString('data-panel-student="'.$student->id.'"', $html);
        $this->assertStringContainsString('Save Student Note', $html);
    }

    public function test_history_link_lives_in_the_card_sub_line_and_points_at_the_student(): void
    {
        $student = $this->student('Alex Rivera');

        $card = $this->card($this->page()->assertOk()->getContent(), $student);

        $this->assertMatchesRegularExpression('/<span class="student-sub"><a class="history-link" href="'.preg_quote(e(url('/students/'.$student->id)), '/').'"[^>]*>History<\/a><\/span>/', $card);
        $this->assertStringContainsString('class="status-badge badge-untouched"', $card);
    }

    public function test_header_has_a_single_class_switcher_and_the_stitch_controls(): void
    {
        SchoolClass::factory()->create(['name' => 'HNL 3M']);
        $this->student('Alex Rivera');

        $html = $this->page()->assertOk()->getContent();
        preg_match('/<header class="top-nav">.*?<\/header>/s', $html, $m);
        $this->assertNotEmpty($m, 'header not found');
        $header = $m[0];

        $this->assertSame(0, substr_count($header, '<select'));
        $this->assertSame(1, substr_count($header, '<details class="class-menu'));
        $this->assertSame(1, substr_count($header, '<summary class="class-menu-summary"'));
        $this->assertStringContainsString('aria-label="Active class: HNL 2O. Choose a class"', $header);
        $this->assertStringNotContainsString('course-chip', $header);
        $this->assertStringNotContainsString('>Switch<', $header);
        $this->assertStringNotContainsString('data-class-form', $header);
        $this->assertStringNotContainsString('Show selected class', $header);
        $this->assertStringContainsString('class="screen-tabs"', $header);
        $this->assertStringContainsString('aria-current="page"', $header);
        $this->assertStringContainsString('data-theme-set="dark"', $header);
        $this->assertStringContainsString('id="undo-btn"', $header);
        $this->assertStringContainsString('Log out', $header);
        $this->assertStringNotContainsString('Login', $header);
        $this->assertStringNotContainsString('PRO', $header);
    }

    public function test_footer_holds_the_real_save_status_and_only_real_shortcuts(): void
    {
        $this->student('Alex Rivera');

        $html = $this->page()->assertOk()->getContent();
        preg_match('/<footer class="app-footer">.*?<\/footer>/s', $html, $m);
        $this->assertNotEmpty($m, 'footer not found');

        $this->assertStringContainsString('id="save-pill"', $m[0]);
        $this->assertStringContainsString('role="status"', $m[0]);
        $this->assertStringContainsString('data-state="saved"', $m[0]);
        $this->assertStringContainsString('ClassPulse · Student Participation Tracker', $m[0]);
        $this->assertStringContainsString('[/] Search', $m[0]);
        $this->assertStringContainsString('[Esc] Close', $m[0]);
        $this->assertStringNotContainsString('Cloud', $m[0]);
    }

    public function test_assets_are_local_and_the_filter_and_toggle_controls_exist(): void
    {
        $this->student('Alex Rivera');

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString('css/fonts.css', $html);
        $this->assertStringContainsString('css/daily.css', $html);
        $this->assertStringNotContainsString('googleapis', $html);
        $this->assertDoesNotMatchRegularExpression('/<(link|script)[^>]+(https?:)?\/\/(?!localhost)/', $html);
        foreach (['all', 'active', 'absent'] as $filter) {
            $this->assertStringContainsString('data-filter="'.$filter.'"', $html);
        }
        $this->assertStringContainsString('id="panel-toggle"', $html);
    }

    public function test_empty_states(): void
    {
        $this->page()->assertOk()->assertSee('Add students or import a CSV');

        $this->class->delete();
        $this->get('/daily')->assertOk()->assertSee('Create your first class');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->app['auth']->guard()->logout();

        $this->get('/daily')->assertRedirect('/login');
    }
}
