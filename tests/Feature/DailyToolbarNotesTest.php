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

class DailyToolbarNotesTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 10:00', 'America/Toronto'));
        $this->actingAs(User::factory()->create());
        $this->class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'title' => 'Nutrition & Health']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function student(string $name, ?string $status = null, ?int $points = null, string $date = '2026-10-21'): Student
    {
        $student = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => $name]);
        if ($status !== null) {
            ParticipationEntry::factory()->create([
                'school_class_id' => $this->class->id,
                'student_id' => $student->id,
                'work_date' => $date,
                'status' => $status,
                'points' => $points,
                'restore_points' => null,
            ]);
        }

        return $student;
    }

    private function page(string $query = '')
    {
        return $this->get('/daily?class='.$this->class->id.$query);
    }

    /** Counter shown in a filter chip. */
    private function chipCount(string $html, string $filter): string
    {
        preg_match('/data-count="'.$filter.'">(\d+)</', $html, $m);
        $this->assertNotEmpty($m, 'counter '.$filter.' not found');

        return $m[1];
    }

    public function test_toolbar_items_are_present_with_accessible_names(): void
    {
        $this->student('Alex Rivera');

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString('role="toolbar" aria-label="Day tools"', $html);
        $this->assertStringContainsString('aria-label="Previous school day"', $html);
        $this->assertMatchesRegularExpression('/<a[^>]*>Today<\/a>/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-student-dialog[^>]*>.*?\+ Student<\/span><\/button>/s', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-print-slip[^>]*>.*?Day Slip<\/span><\/button>/s', $html);
        $this->assertStringContainsString('aria-label="More day actions"', $html);
        $this->assertStringContainsString('<kbd class="ui-kbd" aria-hidden="true">/</kbd>', $html);
        $this->assertStringContainsString('aria-label="Filter students by status"', $html);
        foreach (['all', 'active', 'zero', 'absent', 'none'] as $filter) {
            $this->assertStringContainsString('data-filter="'.$filter.'"', $html);
        }
        // Reset Day and Mark remaining as 0 stay reachable with their dialogs.
        $this->assertStringContainsString('data-dialog="reset-dialog"', $html);
        $this->assertStringContainsString('data-dialog="zero-dialog"', $html);
        $this->assertStringContainsString('id="reset-dialog"', $html);
        $this->assertStringContainsString('id="zero-dialog"', $html);
    }

    public function test_filter_counters_keep_not_recorded_separate_from_zero(): void
    {
        $this->student('Alex Rivera', 'present', 3);
        $this->student('Blair Ito', 'present', 1);
        $this->student('Casey Lee', 'present', 0);
        $this->student('Dana Ng', 'absent');
        $this->student('Eli Park');
        $this->student('Fay Quinn');
        $this->student('Gus Hale');

        $html = $this->page()->assertOk()->getContent();

        $this->assertSame('7', $this->chipCount($html, 'all'));
        $this->assertSame('2', $this->chipCount($html, 'active'));
        $this->assertSame('1', $this->chipCount($html, 'zero'));
        $this->assertSame('1', $this->chipCount($html, 'absent'));
        $this->assertSame('3', $this->chipCount($html, 'none'));
    }

    public function test_day_slip_has_print_hooks_header_table_and_totals(): void
    {
        $alex = $this->student('Alex Rivera', 'present', 4);
        $this->student('Blair Ito', 'present', 0);
        $this->student('Casey Lee', 'absent');
        $this->student('Dana Ng');
        StudentNote::factory()->create(['school_class_id' => $this->class->id, 'student_id' => $alex->id, 'note_date' => '2026-10-21']);

        $html = $this->page()->assertOk()->getContent();

        // Print header from the layout props, hidden on screen and shown by print.css.
        $this->assertStringContainsString('class="print-header"', $html);
        $this->assertMatchesRegularExpression('/print-class">HNL 2O · Nutrition &amp; Health</', $html);
        $this->assertStringContainsString('print-doc">Day Slip<', $html);
        $this->assertStringContainsString('class="print-label">Wednesday, Oct 21, 2026<', $html);

        preg_match('/<section class="day-slip print-only".*?<\/section>/s', $html, $m);
        $this->assertNotEmpty($m, 'day slip not found');
        $slip = $m[0];
        $this->assertStringContainsString('Wednesday, Oct 21, 2026', $slip);
        $this->assertStringContainsString('HNL 2O · Nutrition &amp; Health', $slip);
        $this->assertSame(4, substr_count($slip, '<th scope="row">'));
        $this->assertMatchesRegularExpression('/<th scope="row">Alex Rivera<\/th>\s*<td>Present<\/td>\s*<td class="num">4<\/td>\s*<td>Note<\/td>/', $slip);
        $this->assertMatchesRegularExpression('/<th scope="row">Blair Ito<\/th>\s*<td>Present · 0<\/td>\s*<td class="num">0<\/td>/', $slip);
        $this->assertMatchesRegularExpression('/<th scope="row">Casey Lee<\/th>\s*<td>Absent<\/td>\s*<td class="num">—<\/td>/', $slip);
        $this->assertMatchesRegularExpression('/<th scope="row">Dana Ng<\/th>\s*<td>Not recorded<\/td>\s*<td class="num">—<\/td>/', $slip);
        $this->assertStringContainsString('data-slip="present">2<', $slip);
        $this->assertStringContainsString('data-slip="absent">1<', $slip);
        $this->assertStringContainsString('data-slip="none">1<', $slip);
        $this->assertStringContainsString('data-slip="total">4<', $slip);

        // Everything that is not the slip is hidden when printing.
        foreach (['class="day-toolbar ui-toolbar no-print"', 'class="tracker-stats-banner no-print"', 'class="tracker-layout-grid no-print"'] as $hidden) {
            $this->assertStringContainsString($hidden, $html);
        }
        $css = file_get_contents(public_path('css/print.css'));
        $this->assertStringContainsString('.no-print', $css);
        $this->assertStringContainsString('.day-slip-table', $css);
        $this->assertStringContainsString('.print-only { display: block !important; }', $css);
    }

    public function test_add_student_dialog_posts_to_the_store_route_and_returns_to_the_same_date(): void
    {
        $this->student('Alex Rivera');

        $html = $this->page('&date=2026-10-20')->assertOk()->getContent();
        $this->assertStringContainsString('<dialog id="student-dialog"', $html);
        $this->assertStringContainsString('action="'.url('/classes/'.$this->class->id.'/students').'"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertMatchesRegularExpression('/name="display_name"[^>]*maxlength="120"[^>]*required/', $html);
        $this->assertStringContainsString('name="student_number"', $html);
        $this->assertStringContainsString('maxlength="40"', $html);

        $this->post('/classes/'.$this->class->id.'/students', [
            'display_name' => 'Jordan Lee', 'student_number' => 'A-17', 'return_to' => 'daily', 'date' => '2026-10-20',
        ])->assertRedirect(url('/daily?class='.$this->class->id.'&date=2026-10-20'))
            ->assertSessionHas('status', 'Added Jordan Lee to HNL 2O.');

        $this->assertDatabaseHas('students', ['school_class_id' => $this->class->id, 'display_name' => 'Jordan Lee', 'student_number' => 'A-17']);
        $this->page('&date=2026-10-20')->assertOk()->assertSee('Jordan Lee')->assertSee('Added Jordan Lee to HNL 2O.');
    }

    public function test_add_student_without_the_daily_marker_still_goes_to_the_roster(): void
    {
        $this->post('/classes/'.$this->class->id.'/students', ['display_name' => 'Jordan Lee'])
            ->assertRedirect(url('/roster?class='.$this->class->id));
    }

    public function test_add_student_validation_errors_reopen_the_dialog_with_messages(): void
    {
        $this->student('Alex Rivera');
        $page = url('/daily?class='.$this->class->id.'&date=2026-10-21');

        $this->from($page)->post('/classes/'.$this->class->id.'/students', [
            'display_name' => '   ', 'return_to' => 'daily', 'date' => '2026-10-21',
        ])->assertRedirect($page)->assertSessionHasErrors('display_name');
        $this->from($page)->post('/classes/'.$this->class->id.'/students', [
            'display_name' => str_repeat('a', 121), 'student_number' => str_repeat('9', 41), 'return_to' => 'daily', 'date' => '2026-10-21',
        ])->assertSessionHasErrors(['display_name', 'student_number']);

        $html = $this->from($page)->followingRedirects()->post('/classes/'.$this->class->id.'/students', [
            'display_name' => '   ', 'return_to' => 'daily', 'date' => '2026-10-21',
        ])->assertOk()->getContent();
        $this->assertStringContainsString('data-open-on-load', $html);
        $this->assertStringContainsString('id="new-student-name-error"', $html);
        $this->assertSame(1, Student::where('school_class_id', $this->class->id)->count());
    }

    public function test_add_student_reports_the_roster_cap_in_the_dialog(): void
    {
        $this->class->update(['roster_cap' => 2]);
        $this->student('Alex Rivera');
        $this->student('Blair Ito');
        $page = url('/daily?class='.$this->class->id.'&date=2026-10-21');

        $this->from($page)->post('/classes/'.$this->class->id.'/students', [
            'display_name' => 'Casey Lee', 'return_to' => 'daily', 'date' => '2026-10-21',
        ])->assertRedirect($page)->assertSessionHasErrors('roster_cap');

        $this->assertDatabaseMissing('students', ['display_name' => 'Casey Lee']);
        // The redirect is followed in the same request chain so the flashed errors reach the page.
        $this->from($page)->followingRedirects()->post('/classes/'.$this->class->id.'/students', [
            'display_name' => 'Casey Lee', 'return_to' => 'daily', 'date' => '2026-10-21',
        ])->assertOk()->assertSee('This class is full: 2 of 2 active students. Archive a student to free a seat.')->assertSee('data-open-on-load', false);
    }

    public function test_notes_of_the_shown_date_are_embedded_with_an_indicator_on_the_card(): void
    {
        $alex = $this->student('Alex Rivera', 'present', 2);
        $blair = $this->student('Blair Ito');
        StudentNote::factory()->create(['school_class_id' => $this->class->id, 'student_id' => $alex->id, 'note_date' => '2026-10-21', 'body' => 'Led the group discussion.']);
        StudentNote::factory()->create(['school_class_id' => $this->class->id, 'student_id' => $blair->id, 'note_date' => '2026-10-19', 'body' => 'Was quiet on Monday.']);

        $html = $this->page()->assertOk()->getContent();

        // The textarea carries the note of the shown day for Alex, nothing for Blair.
        $this->assertMatchesRegularExpression('/<textarea id="note-'.$alex->id.'"[^>]*maxlength="2000"[^>]*placeholder="Log a quick observation…"[^>]*>Led the group discussion\.<\/textarea>/', $html);
        $this->assertMatchesRegularExpression('/<textarea id="note-'.$blair->id.'"[^>]*><\/textarea>/', $html);
        $this->assertStringContainsString('for="note-'.$alex->id.'">Session anecdotal remark<', $html);
        $this->assertStringContainsString('Save Student Note', $html);
        $this->assertStringContainsString('data-note-url="'.url('/api/classes/'.$this->class->id.'/students/'.$alex->id.'/notes/2026-10-21').'"', $html);
        $this->assertStringContainsString('data-note-saved="Led the group discussion."', $html);
        $this->assertStringContainsString('>25 / 2000<', $html);

        preg_match('/<article class="student-card" data-student-id="'.$alex->id.'".*?<\/article>/s', $html, $card);
        $this->assertDoesNotMatchRegularExpression('/data-note-indicator\s+hidden/', $card[0]);
        preg_match('/<article class="student-card" data-student-id="'.$blair->id.'".*?<\/article>/s', $html, $other);
        $this->assertMatchesRegularExpression('/data-note-indicator\s+hidden/', $other[0]);

        // Another day shows that day's note and lists the other one under "Recent notes".
        $monday = $this->page('&date=2026-10-19')->assertOk()->getContent();
        $this->assertStringContainsString('>Was quiet on Monday.</textarea>', $monday);
        $this->assertStringContainsString('Recent notes', $monday);
        $this->assertStringContainsString('Led the group discussion.', $monday);
    }

    public function test_notes_round_trip_through_the_existing_api_and_survive_a_reload(): void
    {
        $alex = $this->student('Alex Rivera', 'present', 1);
        $base = '/api/classes/'.$this->class->id.'/students/'.$alex->id.'/notes/2026-10-21';

        $this->putJson($base, ['body' => '  Explained ATP synthesis to a peer.  '])
            ->assertOk()->assertJsonPath('data.note.body', 'Explained ATP synthesis to a peer.');
        $this->page()->assertOk()->assertSee('>Explained ATP synthesis to a peer.</textarea>', false);

        // Clearing and saving (empty body) deletes the note and the indicator disappears.
        $this->putJson($base, ['body' => ''])->assertOk()->assertJsonPath('data.deleted', true);
        $html = $this->page()->assertOk()->getContent();
        $this->assertStringNotContainsString('Explained ATP', $html);
        $this->assertMatchesRegularExpression('/data-note-indicator\s+hidden/', $html);
        $this->assertDatabaseCount('student_notes', 0);

        // Over 2000 characters is rejected with the JSON error shape.
        $this->putJson($base, ['body' => str_repeat('x', 2001)])->assertStatus(422)->assertJsonPath('error.code', 'validation');
    }

    public function test_cadence_chart_needs_two_weeks_with_data_and_shows_real_averages(): void
    {
        $alex = $this->student('Alex Rivera', 'present', 4, '2026-10-21');

        $one = $this->page()->assertOk()->getContent();
        $this->assertStringContainsString('Not enough weeks recorded yet', $one);
        $this->assertStringNotContainsString('cadence-chart', $one);

        foreach (['2026-10-14' => 2, '2026-10-07' => 6] as $date => $points) {
            ParticipationEntry::factory()->create([
                'school_class_id' => $this->class->id, 'student_id' => $alex->id, 'work_date' => $date,
                'status' => 'present', 'points' => $points, 'restore_points' => null,
            ]);
        }

        $html = $this->page()->assertOk()->getContent();
        $this->assertStringContainsString('class="ui-chart cadence-chart"', $html);
        $this->assertStringContainsString('Weekly average per present day, last 3 weeks: week of Oct 5: 6.00; week of Oct 12: 2.00; week of Oct 19: 4.00', $html);
        $this->assertSame(3, substr_count($html, 'class="ui-chart-bar'));
        $this->assertStringContainsString('ui-chart-bar is-last', $html);
    }

    public function test_panel_header_shows_the_preferred_name_and_keeps_the_empty_state(): void
    {
        $alex = $this->student('Alex Rivera');
        $alex->update(['preferred_name' => 'Lex']);

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString('Preferred: Lex', $html);
        $this->assertStringContainsString('<div class="inspector-empty" data-panel-empty>', $html);
        $this->assertStringContainsString('Select a student', $html);
        $this->assertStringContainsString('Semester cadence trend', $html);
        $this->assertStringContainsString('Current week breakdown', $html);
    }

    public function test_the_note_belongs_to_the_class_of_the_page(): void
    {
        $alex = $this->student('Alex Rivera');
        $other = SchoolClass::factory()->create();

        $this->putJson('/api/classes/'.$other->id.'/students/'.$alex->id.'/notes/2026-10-21', ['body' => 'Nope'])->assertNotFound();
        $this->assertDatabaseCount('student_notes', 0);
    }
}
