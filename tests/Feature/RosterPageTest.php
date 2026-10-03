<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\StudentName;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RosterPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 10:00', 'America/Toronto'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create());
    }

    /** The HTML of the first element that starts at $start and ends at $end. */
    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "Missing $start");
        $to = strpos($html, $end, $from);
        $this->assertNotFalse($to, "Missing $end");

        return substr($html, $from, $to - $from);
    }

    public function test_delete_requires_the_exact_class_name(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O']);
        $student = Student::factory()->create(['school_class_id' => $class->id]);
        ParticipationEntry::factory()->create([
            'school_class_id' => $class->id,
            'student_id' => $student->id,
        ]);

        $this->delete('/classes/'.$class->id, ['confirm_name' => 'hnl 2o'])
            ->assertSessionHasErrors(['confirm_name' => 'Type the class name exactly to confirm.']);
        $this->assertSame(1, SchoolClass::count());

        $this->delete('/classes/'.$class->id)->assertSessionHasErrors('confirm_name');
        $this->assertSame(1, SchoolClass::count());

        $this->delete('/classes/'.$class->id, ['confirm_name' => 'HNL 2O'])->assertRedirect('/roster');
        $this->assertSame(0, SchoolClass::count());
        $this->assertSame(0, Student::count());
        $this->assertSame(0, ParticipationEntry::count());
    }

    public function test_delete_confirmation_warns_and_is_the_only_irreversible_action(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O']);
        $students = Student::factory()->count(3)->create(['school_class_id' => $class->id]);
        foreach ($students as $student) {
            ParticipationEntry::factory()->create(['school_class_id' => $class->id, 'student_id' => $student->id]);
        }

        $html = $this->get('/roster?class='.$class->id)->assertOk()->getContent();

        $danger = $this->between($html, 'aria-labelledby="danger-title"', '</section>');
        $this->assertStringContainsString('Delete this class', $danger);
        $this->assertStringContainsString('This permanently deletes HNL 2O, 3 students and 3 entries.', $danger);
        $this->assertStringContainsString('Type the class name to confirm', $danger);
        $this->assertStringContainsString('name="confirm_name"', $danger);
        $this->assertStringContainsString('name="_method" value="DELETE"', $danger);
        // Students are archived, never deleted: the page has no form that deletes a student.
        $this->assertDoesNotMatchRegularExpression('#action="[^"]*/students/\d+"[^>]*>\s*<input[^>]*name="_token"[^>]*>\s*<input[^>]*name="_method" value="DELETE"#', $html);
    }

    public function test_empty_state_opens_the_create_form_without_classes(): void
    {
        $this->signIn();

        $response = $this->get('/roster')->assertOk()
            ->assertSee('Create your first class')
            ->assertSee('Create New Class');
        $this->assertMatchesRegularExpression('/id="create-class"\s+open/', $response->getContent());
    }

    public function test_class_cards_show_code_subject_count_active_dot_and_selected_state(): void
    {
        $this->signIn();
        $a = SchoolClass::factory()->create(['name' => 'HNL 2O', 'title' => 'Nutrition & Health']);
        $b = SchoolClass::factory()->create(['name' => 'HNC 3C', 'title' => null, 'subject_description' => 'Food and Culture']);
        Student::factory()->count(2)->create(['school_class_id' => $a->id]);
        Student::factory()->archived()->create(['school_class_id' => $a->id]);
        Student::factory()->count(1)->create(['school_class_id' => $b->id]);

        $html = $this->get('/roster?class='.$a->id)->assertOk()->getContent();
        $cards = $this->between($html, '<nav class="ro-classes', '</nav>');

        $this->assertStringContainsString('href="'.url('/roster?class='.$a->id).'"', $cards);
        $this->assertStringContainsString('href="'.url('/roster?class='.$b->id).'"', $cards);
        $this->assertStringContainsString('Nutrition &amp; Health • 2 students', $cards, 'archived students are not counted');
        $this->assertStringContainsString('Food and Culture • 1 student', $cards);
        $this->assertSame(1, substr_count($cards, 'aria-current="true"'));
        $this->assertSame(1, substr_count($cards, 'ro-class-dot'));
        $this->assertStringContainsString('href="#create-class"', $cards);
        $this->assertStringContainsString('Create New Class', $cards);
    }

    public function test_create_flow_saves_every_field_and_lands_on_the_new_class(): void
    {
        $this->signIn();

        $response = $this->post('/classes', [
            'form' => 'create',
            'name' => 'ZZ Demo (temp)',
            'title' => 'Scratch',
            'subject_description' => 'Temp subject',
            'period_label' => 'Period 9',
            'schedule' => '09:00 - 10:00',
            'room' => 'Rm 1',
            'roster_cap' => 12,
            'semester_start' => '2026-09-01',
            'semester_end' => '2027-01-29',
        ]);

        $class = SchoolClass::sole();
        $response->assertRedirect('/roster?class='.$class->id)->assertSessionHas('status', 'Created ZZ Demo (temp).');
        $this->assertSame(['Scratch', 'Temp subject', 'Period 9', '09:00 - 10:00', 'Rm 1', 12], [
            $class->title, $class->subject_description, $class->period_label, $class->schedule, $class->room, $class->roster_cap,
        ]);
        $this->assertSame('2026-09-01', $class->semester_start->format('Y-m-d'));
    }

    public function test_create_form_shows_errors_inline_and_keeps_what_was_typed(): void
    {
        $this->signIn();
        SchoolClass::factory()->create(['name' => 'HNL 2O']);

        // assertSessionHasErrors() empties the flashed errors, so the error keys and the page are checked on separate attempts.
        $payload = ['form' => 'create', 'name' => 'HNL 2O', 'title' => 'Kept title', 'roster_cap' => 99];
        $this->from('/roster?create=1')->post('/classes', $payload)->assertRedirect('/roster?create=1')->assertSessionHasErrors(['name', 'roster_cap']);
        $this->from('/roster?create=1')->post('/classes', $payload);

        $resp = $this->get('/roster?create=1');
        $html = $resp->getContent();
        $create = $this->between($html, 'id="create-class"', '</details>');
        $this->assertStringContainsString('The name has already been taken.', $create);
        $this->assertStringContainsString('id="new-name-error"', $create);
        $this->assertStringContainsString('value="Kept title"', $create);
        $this->assertStringContainsString('max="'.config('classpulse.max_roster').'"', $create);
    }

    public function test_details_form_carries_every_field_and_saves_with_a_flash(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'roster_cap' => 30]);

        $html = $this->get('/roster?class='.$class->id)->assertOk()->getContent();
        $details = $this->between($html, 'aria-labelledby="details-title"', 'aria-labelledby="rules-title"');
        $this->assertStringContainsString('Class Details: HNL 2O', $details);
        $this->assertStringContainsString('Active block', $details);
        foreach (['name', 'title', 'period_label', 'schedule', 'room', 'roster_cap', 'subject_description', 'semester_start', 'semester_end', 'q1_label', 'q1_start', 'q1_end', 'q2_label', 'q2_start', 'q2_end'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $details, $field);
        }
        $this->assertStringContainsString('Save class details', $details);
        $this->assertStringContainsString('id="periods"', $details);
        $this->assertStringContainsString('Academic periods', $details);
        $this->assertStringContainsString('Q1/Q2 appear in Semester Analytics only when dates are set', $details);

        $this->from('/roster?class='.$class->id)->put('/classes/'.$class->id, [
            'form' => 'details', 'name' => 'HNL 2O', 'title' => 'Nutrition & Health', 'room' => 'Rm 214', 'schedule' => 'Period 2 (10:15 - 11:35)', 'roster_cap' => 28,
        ])->assertRedirect('/roster?class='.$class->id)->assertSessionHas('status');

        $fresh = $class->fresh();
        $this->assertSame(['Nutrition & Health', 'Rm 214', 28], [$fresh->title, $fresh->room, $fresh->roster_cap]);
    }

    public function test_period_errors_show_inline_under_their_own_field(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'semester_start' => '2026-09-01', 'semester_end' => '2027-01-29']);
        $url = '/roster?class='.$class->id;

        $overlap = [
            'form' => 'details', 'name' => 'HNL 2O', 'roster_cap' => 30, 'semester_start' => '2026-09-01', 'semester_end' => '2027-01-29',
            'q1_label' => 'Term 1', 'q1_start' => '2026-09-01', 'q1_end' => '2026-11-13',
            'q2_label' => '', 'q2_start' => '2026-11-06', 'q2_end' => '2027-01-29',
        ];
        $this->from($url)->put('/classes/'.$class->id, $overlap)->assertRedirect($url)->assertSessionHasErrors('q2_start');
        $this->assertSame(0, $class->academicPeriods()->count(), 'nothing is saved when a period is invalid');
        $this->from($url)->put('/classes/'.$class->id, $overlap);

        $html = $this->get($url)->getContent();
        $periods = $this->between($html, 'id="periods"', '</fieldset>');
        $this->assertStringContainsString('Q1 and Q2 must not overlap.', $periods);
        $this->assertStringContainsString('id="q2-start-error"', $periods);
        $this->assertStringNotContainsString('id="q1-start-error"', $periods);
        $this->assertStringContainsString('value="Term 1"', $periods, 'typed values survive the error');
        $this->assertStringContainsString('aria-invalid="true"', $periods);

        $half = ['form' => 'details', 'name' => 'HNL 2O', 'roster_cap' => 30, 'q1_start' => '2026-09-01', 'q1_end' => ''];
        $this->from($url)->put('/classes/'.$class->id, $half)->assertSessionHasErrors('q1_end');
        $this->from($url)->put('/classes/'.$class->id, $half);
        $html = $this->get($url)->getContent();
        $this->assertStringContainsString('id="q1-end-error"', $this->between($html, 'id="periods"', '</fieldset>'));
    }

    public function test_settings_groups_share_one_form_and_a_group_with_errors_is_open(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'semester_start' => '2026-09-01', 'semester_end' => '2027-01-29']);
        $url = '/roster?class='.$class->id;

        $html = $this->get($url)->assertOk()->getContent();
        $form = $this->between($html, 'aria-labelledby="details-title"', 'aria-labelledby="rules-title"');
        $this->assertSame(1, substr_count($form, '<form'), 'one form carries every group');
        $this->assertSame(1, substr_count($form, 'type="submit"'), 'one Save button');
        $this->assertMatchesRegularExpression('/id="class-details" data-ro-group\s+open/', $html, 'first group is open');
        $this->assertDoesNotMatchRegularExpression('/id="periods" data-ro-group\s+open/', $html, 'periods start collapsed without errors');
        $this->assertStringContainsString('Calculation Rules', $html);
        $this->assertStringContainsString('Delete this class', $html);

        $this->from($url)->put('/classes/'.$class->id, ['form' => 'details', 'name' => 'HNL 2O', 'roster_cap' => 30, 'q1_start' => '2026-09-01', 'q1_end' => ''])->assertSessionHasErrors('q1_end');
        $this->from($url)->put('/classes/'.$class->id, ['form' => 'details', 'name' => 'HNL 2O', 'roster_cap' => 30, 'q1_start' => '2026-09-01', 'q1_end' => '']);
        $html = $this->get($url)->getContent();
        $this->assertMatchesRegularExpression('/id="periods" data-ro-group\s+open/', $html, 'the group holding the error opens on reload');
        $this->assertStringContainsString('1 to fix', $html);
    }

    public function test_saved_periods_are_shown_on_the_roster_and_on_semester_analytics(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'semester_start' => '2026-09-01', 'semester_end' => '2027-01-29']);

        $this->get('/semester?class='.$class->id)->assertOk()->assertSee('Set period dates');

        $this->put('/classes/'.$class->id, [
            'form' => 'details', 'name' => 'HNL 2O', 'roster_cap' => 30, 'semester_start' => '2026-09-01', 'semester_end' => '2027-01-29',
            'q1_label' => 'Term 1', 'q1_start' => '2026-09-01', 'q1_end' => '2026-11-13',
            'q2_label' => '', 'q2_start' => '2026-11-16', 'q2_end' => '2027-01-29',
        ])->assertSessionHasNoErrors();

        $periods = $this->between($this->get('/roster?class='.$class->id)->getContent(), 'id="periods"', '</fieldset>');
        $this->assertStringContainsString('value="2026-11-16"', $periods);
        $this->assertStringContainsString('value="Term 1"', $periods);

        $this->get('/semester?class='.$class->id)->assertOk()->assertSee('Term 1')->assertSee('Q2 / Finals')->assertSee('Edit period dates');
    }

    public function test_capacity_meter_shows_real_numbers_and_the_full_state(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'roster_cap' => 4]);
        Student::factory()->count(3)->create(['school_class_id' => $class->id]);
        Student::factory()->archived()->create(['school_class_id' => $class->id]);

        $html = $this->get('/roster?class='.$class->id)->getContent();
        $meter = $this->between($html, 'data-capacity', '</progress>');
        $this->assertStringContainsString('3 / 4 seats (75%)', $meter);
        $this->assertStringContainsString('value="3" max="4"', $meter);
        $this->assertStringNotContainsString('is-warn', $meter);
        $this->assertStringNotContainsString('>Full<', $meter);
        $this->assertStringContainsString('3 Active Students', $html);

        Student::factory()->create(['school_class_id' => $class->id]);
        $meter = $this->between($this->get('/roster?class='.$class->id)->getContent(), 'data-capacity', '</progress>');
        $this->assertStringContainsString('4 / 4 seats (100%)', $meter);
        $this->assertStringContainsString('>Full<', $meter);
        $this->assertStringContainsString('is-warn', $meter);
    }

    public function test_capacity_meter_warns_from_ninety_percent(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'roster_cap' => 10]);
        Student::factory()->count(8)->create(['school_class_id' => $class->id]);
        $this->assertStringNotContainsString('is-warn', $this->between($this->get('/roster?class='.$class->id)->getContent(), 'data-capacity', '</progress>'));

        Student::factory()->create(['school_class_id' => $class->id]);
        $meter = $this->between($this->get('/roster?class='.$class->id)->getContent(), 'data-capacity', '</progress>');
        $this->assertStringContainsString('is-warn', $meter);
        $this->assertStringNotContainsString('>Full<', $meter);
    }

    public function test_calculation_rules_are_locked_items_without_toggles(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O']);

        $html = $this->get('/roster?class='.$class->id)->getContent();
        $rules = $this->between($html, 'aria-labelledby="rules-title"', '</section>');

        $this->assertStringContainsString('Always on', $rules);
        foreach ([
            'Floor limit (zero-bound)', 'Participation points never drop below 0.',
            'Exclude absent days', 'Absences are left out of every average.',
            'Exclude not-recorded days', 'Days without an entry are left out of every average.',
            'Points are not converted to a grade', 'Participation weight is not applied without a defined conversion scale.',
        ] as $text) {
            $this->assertStringContainsString($text, $rules);
        }
        $this->assertSame(4, substr_count($rules, '#icon-lock'), 'one lock icon per rule');
        $this->assertStringNotContainsString('<input', $rules);
        $this->assertStringNotContainsString('type="checkbox"', $rules);
        $this->assertStringNotContainsString('role="switch"', $rules);
        $this->assertStringNotContainsString('<button', $rules);
        $this->assertStringNotContainsString('Roster Verified', $html);
        $this->assertStringNotContainsString('Course weight', $html);
        $this->assertStringNotContainsString('IEP', $html);
    }

    public function test_roster_rows_show_the_real_average_today_status_and_profile_fields(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O']);
        $alex = Student::factory()->create(['school_class_id' => $class->id, 'display_name' => 'Alex Rivera', 'preferred_name' => 'Ali', 'student_number' => 'S-1', 'observations' => 'Sits near the front.']);
        $blair = Student::factory()->create(['school_class_id' => $class->id, 'display_name' => 'Blair Cole']);
        Student::factory()->create(['school_class_id' => $class->id, 'display_name' => 'Casey Dean']);
        foreach ([['2026-10-19', 4], ['2026-10-20', 2], ['2026-10-26', 3]] as [$date, $points]) {
            ParticipationEntry::factory()->create(['school_class_id' => $class->id, 'student_id' => $alex->id, 'work_date' => $date, 'points' => $points]);
        }
        ParticipationEntry::factory()->create(['school_class_id' => $class->id, 'student_id' => $blair->id, 'work_date' => '2026-10-26', 'status' => 'absent', 'points' => null]);

        $html = $this->get('/roster?class='.$class->id)->getContent();
        $rows = explode('data-student-row', $html);
        $this->assertCount(4, $rows, 'three active students');

        $alexRow = $rows[3];
        $this->assertStringContainsString('Alex Rivera', $alexRow);
        $this->assertStringContainsString('Preferred: Ali', $alexRow);
        $this->assertStringContainsString('S-1', $alexRow);
        $this->assertStringContainsString('Sits near the front.', $alexRow);
        $this->assertStringContainsString('>3.00<', $alexRow);
        $this->assertStringContainsString('Present · 3 pts', $alexRow);
        $this->assertStringContainsString('aria-label="Edit Alex Rivera"', $alexRow);
        $this->assertStringContainsString('data-avg="3"', $alexRow);

        // Default order is last name: Cole (Blair), Dean (Casey), Rivera (Alex).
        $this->assertStringContainsString('Blair Cole', $rows[1]);
        $this->assertStringContainsString('Absent', $rows[1]);
        $this->assertStringContainsString('No data', $rows[1]);
        $this->assertStringContainsString('Casey Dean', $rows[2]);
        $this->assertStringContainsString('Not recorded', $rows[2]);
        $this->assertStringContainsString('ro-empty', $rows[2], 'empty observations show a dash');
    }

    public function test_default_order_is_last_name_and_students_of_other_classes_never_appear(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O']);
        $other = SchoolClass::factory()->create(['name' => 'HNC 3C']);
        foreach (['Zoe Adams', 'Adam Zimmer', 'Mia Brown'] as $name) {
            Student::factory()->create(['school_class_id' => $class->id, 'display_name' => $name]);
        }
        Student::factory()->create(['school_class_id' => $other->id, 'display_name' => 'Outsider Person']);
        Student::factory()->archived()->create(['school_class_id' => $other->id, 'display_name' => 'Archived Outsider']);

        $html = $this->get('/roster?class='.$class->id)->getContent();
        $this->assertStringContainsString('3 Active Students', $html);
        $this->assertSame(3, substr_count($html, 'data-student-row data-id'));
        $positions = array_map(fn (string $n) => strpos($html, $n), ['Zoe Adams', 'Mia Brown', 'Adam Zimmer']);
        $this->assertSame($positions, collect($positions)->sort()->values()->all(), 'Adams, Brown, Zimmer');
        $this->assertStringNotContainsString('Outsider Person', $html);
        $this->assertStringNotContainsString('Archived Outsider', $html);
    }

    public function test_name_helpers_follow_the_last_name_and_initials_rules(): void
    {
        $this->assertSame('rivera|alex rivera', StudentName::lastNameKey('Alex Rivera'));
        $this->assertSame('alvarez|alvarez, alejandro', StudentName::lastNameKey('Alvarez, Alejandro'));
        $this->assertSame('AR', StudentName::initials('Alex Rivera'));
        $this->assertSame('AA', StudentName::initials('Alvarez, Alejandro'));
        $this->assertSame('M', StudentName::initials('Madonna'));
        $this->assertSame('?', StudentName::initials('  '));
    }

    public function test_quick_add_creates_a_student_and_the_cap_error_is_shown_beside_it(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'roster_cap' => 2]);
        $url = '/roster?class='.$class->id;

        $this->post('/classes/'.$class->id.'/students', ['form' => 'quick', 'display_name' => 'Jordan Lee', 'student_number' => 'J-7'])
            ->assertRedirect($url)->assertSessionHas('status', 'Added Jordan Lee.');
        $student = Student::sole();
        $this->assertSame(['Jordan Lee', 'J-7', $class->id], [$student->display_name, $student->student_number, $student->school_class_id]);

        $html = $this->get($url)->getContent();
        $quick = $this->between($html, 'class="ro-quick', '</form>');
        $this->assertStringContainsString('name="display_name"', $quick);
        $this->assertStringContainsString('name="student_number"', $quick);
        $this->assertStringContainsString('Quick add: first &amp; last name…', $quick);
        $this->assertStringContainsString('>Add<', $quick);

        Student::factory()->create(['school_class_id' => $class->id]);
        $overflow = ['form' => 'quick', 'display_name' => 'Overflow Person'];
        $this->from($url)->post('/classes/'.$class->id.'/students', $overflow)
            ->assertRedirect($url)->assertSessionHasErrors(['roster_cap' => 'This class is full: 2 of 2 active students. Archive a student to free a seat.']);
        $this->assertSame(2, Student::count());
        $this->from($url)->post('/classes/'.$class->id.'/students', $overflow);

        $html = $this->get($url)->getContent();
        $this->assertStringContainsString('id="quick-errors"', $html);
        $this->assertStringContainsString('This class is full: 2 of 2 active students. Archive a student to free a seat.', $html);
        $this->assertStringContainsString('value="Overflow Person"', $html, 'the typed name stays in the field');
    }

    public function test_bulk_import_and_print_controls_and_the_import_flash(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O']);

        $html = $this->get('/roster?class='.$class->id)->getContent();
        $this->assertStringContainsString('href="'.url('/classes/'.$class->id.'/import').'"', $html);
        $this->assertStringContainsString('Bulk CSV Import', $html);
        $this->assertStringContainsString('data-print-roster', $html);
        $this->assertStringContainsString('aria-label="Print roster"', $html);
        $this->assertStringContainsString('class="print-header"', $html, 'print header with class, title and date');
        $this->assertStringContainsString('Class Roster', $this->between($html, 'class="print-title"', '</p>'));

        $this->withSession(['status' => 'Imported 3 students.'])->get('/roster?class='.$class->id)
            ->assertSee('class="form-success notice no-print" role="status"', false)
            ->assertSee('Imported 3 students.');
    }

    public function test_import_pages_link_back_to_the_roster(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O']);
        $back = 'href="'.url('/roster?class='.$class->id).'"';

        $this->get('/classes/'.$class->id.'/import')->assertOk()->assertSee('Back to roster')->assertSee($back, false);
        $this->post('/classes/'.$class->id.'/import/preview', ['pasted' => "Ana Lopez\n"])->assertOk()->assertSee('Back to roster')->assertSee($back, false);
    }

    public function test_search_sort_and_counter_hooks_are_present(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O']);
        Student::factory()->count(2)->create(['school_class_id' => $class->id]);

        $html = $this->get('/roster?class='.$class->id)->getContent();
        $this->assertStringContainsString('data-roster-search', $html);
        $this->assertStringContainsString('aria-keyshortcuts="/"', $html);
        $this->assertStringContainsString('data-roster-sort', $html);
        foreach (['last-asc' => 'Last name (A–Z)', 'name-asc' => 'Name (A–Z)', 'avg-desc' => 'Participation avg (high to low)', 'recent' => 'Recently added'] as $value => $label) {
            $this->assertStringContainsString('<option value="'.$value.'">'.$label.'</option>', $html);
        }
        $this->assertStringContainsString('data-roster-count', $html);
        $this->assertStringContainsString('Showing <strong>2</strong> of 2 students', $html);
        $this->assertStringContainsString('data-roster-nomatch', $html);
        $this->assertStringContainsString('js/roster-lib.js', $html);
        $this->assertStringContainsString('js/roster.js', $html);
        // Print keeps the roster columns only: controls, Today and Actions carry no-print.
        $this->assertGreaterThanOrEqual(2, substr_count($this->between($html, '<thead>', '</thead>'), 'no-print'));
    }

    public function test_edit_student_saves_profile_fields_and_failures_reopen_the_dialog_with_inline_errors(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O']);
        $alex = Student::factory()->create(['school_class_id' => $class->id, 'display_name' => 'Alex Rivera']);
        $url = '/roster?class='.$class->id;

        $html = $this->get($url)->getContent();
        $edit = $this->between($html, 'data-edit-student', '</button>');
        $this->assertStringContainsString('data-action="'.url('/students/'.$alex->id).'"', $edit);
        $dialog = $this->between($html, '<dialog id="edit-dialog"', '</dialog>');
        foreach (['display_name', 'preferred_name', 'student_number', 'observations'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $dialog);
        }
        $this->assertStringContainsString('name="_token"', $dialog);
        $this->assertStringContainsString('maxlength="80"', $dialog);
        $this->assertStringContainsString('maxlength="40"', $dialog);
        $this->assertStringContainsString('maxlength="2000"', $dialog);

        $this->put('/students/'.$alex->id, ['form' => 'edit', 'display_name' => 'Alex Rivera-Cole', 'preferred_name' => 'Ali', 'student_number' => 'S-9', 'observations' => 'Left-handed.'])
            ->assertRedirect($url)->assertSessionHas('status', 'Saved Alex Rivera-Cole.');
        $fresh = $alex->fresh();
        $this->assertSame(['Alex Rivera-Cole', 'Ali', 'S-9', 'Left-handed.'], [$fresh->display_name, $fresh->preferred_name, $fresh->student_number, $fresh->observations]);

        $bad = ['form' => 'edit', 'edit_student' => $alex->id, 'display_name' => '', 'preferred_name' => str_repeat('p', 81), 'observations' => str_repeat('o', 2001)];
        $this->from($url)->put('/students/'.$alex->id, $bad)
            ->assertRedirect($url)->assertSessionHasErrors(['display_name', 'preferred_name', 'observations']);
        $this->assertSame('Alex Rivera-Cole', $alex->fresh()->display_name);
        $this->from($url)->put('/students/'.$alex->id, $bad);

        $dialog = $this->between($this->get($url)->getContent(), '<dialog id="edit-dialog"', '</dialog>');
        $this->assertStringContainsString('data-open-on-load', $dialog);
        $this->assertStringContainsString('action="'.url('/students/'.$alex->id).'"', $dialog);
        foreach (['edit-display-error', 'edit-preferred-error', 'edit-observations-error'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $dialog);
        }
        $this->assertStringContainsString('must not be greater than 80 characters', $dialog);
    }

    public function test_archive_keeps_records_and_the_student_moves_to_the_archived_list_with_its_date(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O']);
        $alex = Student::factory()->create(['school_class_id' => $class->id, 'display_name' => 'Alex Rivera', 'observations' => 'Keep me']);
        Student::factory()->create(['school_class_id' => $class->id, 'display_name' => 'Blair Cole']);
        ParticipationEntry::factory()->create(['school_class_id' => $class->id, 'student_id' => $alex->id, 'work_date' => '2026-10-20', 'points' => 5]);
        $url = '/roster?class='.$class->id;

        $html = $this->get($url)->getContent();
        $this->assertStringContainsString('Archive Alex Rivera? Their records are kept and you can restore them anytime.', $html);
        $this->assertStringContainsString('aria-label="Confirm archive Alex Rivera"', $html);
        $this->assertStringContainsString('#icon-archive"', $html);
        $this->assertStringNotContainsString('icon-trash', $html);

        $this->post('/students/'.$alex->id.'/archive')->assertRedirect($url)->assertSessionHas('status');

        $fresh = $alex->fresh();
        $this->assertNotNull($fresh->archived_at);
        $this->assertSame('Keep me', $fresh->observations);
        $this->assertSame(1, ParticipationEntry::where('student_id', $alex->id)->count());

        $html = $this->get($url)->getContent();
        $this->assertStringContainsString('1 Active Student<', $html);
        $active = $this->between($html, '<tbody data-roster-body>', '</tbody>');
        $this->assertStringNotContainsString('Alex Rivera', $active);
        $archived = $this->between($html, 'id="archived"', '</details>');
        $this->assertStringContainsString('Archived students', $archived);
        $this->assertStringContainsString('Alex Rivera', $archived);
        $this->assertStringContainsString('Archived Oct 26, 2026 · records kept', $archived);
        $this->assertStringContainsString('aria-label="Restore Alex Rivera"', $archived);

        $this->post('/students/'.$alex->id.'/restore')->assertRedirect($url)->assertSessionHas('status', 'Restored Alex Rivera.');
        $this->assertNull($alex->fresh()->archived_at);
        $this->assertSame(1, ParticipationEntry::where('student_id', $alex->id)->count());
    }

    public function test_restore_over_the_cap_is_refused_with_an_inline_message(): void
    {
        $this->signIn();
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'roster_cap' => 2]);
        Student::factory()->count(2)->create(['school_class_id' => $class->id]);
        $old = Student::factory()->archived()->create(['school_class_id' => $class->id, 'display_name' => 'Old Student']);
        $url = '/roster?class='.$class->id;

        $this->from($url)->post('/students/'.$old->id.'/restore', ['form' => 'restore'])
            ->assertRedirect($url)
            ->assertSessionHasErrors(['roster_cap' => 'This class is full: 2 of 2 active students. Archive a student to free a seat.']);
        $this->assertNotNull($old->fresh()->archived_at);
        $this->from($url)->post('/students/'.$old->id.'/restore', ['form' => 'restore']);

        $html = $this->get($url)->getContent();
        $archived = $this->between($html, 'id="archived"', '</details>');
        $this->assertMatchesRegularExpression('/id="archived"\s+open/', $html);
        $this->assertStringContainsString('This class is full: 2 of 2 active students', $archived);
        $this->assertStringNotContainsString('id="quick-errors"', $html, 'the cap error belongs to the archived list');
    }

    public function test_no_route_deletes_a_student_or_its_records(): void
    {
        $this->signIn();

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            // Dated notes and report comment drafts (api/.../notes/{date}, .../report-comments/{period}) are separate resources with their own delete; students themselves are never deleted.
            if ((str_contains($uri, 'students') || str_contains($uri, 'student/')) && ! str_contains($uri, '/notes') && ! str_contains($uri, '/report-comments')) {
                $this->assertNotContains('DELETE', $route->methods(), $uri.' must not accept DELETE');
            }
        }
        $student = Student::factory()->create();
        $this->delete('/students/'.$student->id)->assertStatus(405);
        $this->assertSame(1, Student::count());
    }

    public function test_guests_are_redirected_and_nothing_is_deleted(): void
    {
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O']);

        $this->get('/roster')->assertRedirect('/login');
        $this->delete('/classes/'.$class->id, ['confirm_name' => 'HNL 2O'])->assertRedirect('/login');

        $this->assertSame(1, SchoolClass::count());
    }
}
