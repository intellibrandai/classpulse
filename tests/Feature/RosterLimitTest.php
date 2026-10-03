<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\RosterImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The 35 active-students limit (config classpulse.max_roster) on every path that creates or restores a student.
 */
class RosterLimitTest extends TestCase
{
    use RefreshDatabase;

    private const FULL = 'This class is full: 35 of 35 active students. Archive a student to free a seat.';

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->class = SchoolClass::factory()->create(['name' => 'HNL 2O']);
    }

    private function seats(int $count, ?SchoolClass $class = null): void
    {
        Student::factory()->count($count)->create(['school_class_id' => ($class ?? $this->class)->id]);
    }

    private function names(int $count): string
    {
        return implode("\n", array_map(fn (int $i) => 'Student '.sprintf('%02d', $i), range(1, $count)))."\n";
    }

    private function csv(int $count): string
    {
        return "name,student_number\n".implode("\n", array_map(fn (int $i) => 'Student '.sprintf('%02d', $i).',N'.$i, range(1, $count)))."\n";
    }

    private function previewFile(string $csv)
    {
        return $this->post('/classes/'.$this->class->id.'/import/preview', ['file' => UploadedFile::fake()->createWithContent('roster.csv', $csv)]);
    }

    private function token(string $html): string
    {
        preg_match('/name="token" value="([^"]+)"/', $html, $m);

        return $m[1];
    }

    public function test_the_limit_lives_in_one_config_value_and_new_classes_follow_it(): void
    {
        $this->assertSame(35, config('classpulse.max_roster'));
        $this->assertSame(35, SchoolClass::factory()->create()->fresh()->roster_cap);
        $this->assertSame(35, $this->class->seatLimit());
        $this->get('/roster?create=1')->assertOk()->assertSee('max="35"', false)->assertSee('Up to 35 seats.');
        $this->post('/classes', ['form' => 'create', 'name' => 'New', 'roster_cap' => 36])->assertSessionHasErrors('roster_cap');
        $this->post('/classes', ['form' => 'create', 'name' => 'New', 'roster_cap' => 35])->assertSessionDoesntHaveErrors();
    }

    public function test_student_35_is_admitted_and_36_is_rejected_on_the_roster_and_daily_paths(): void
    {
        $this->seats(34);

        $this->post('/classes/'.$this->class->id.'/students', ['form' => 'quick', 'display_name' => 'Student 35'])->assertSessionDoesntHaveErrors();
        $this->assertSame(35, $this->class->activeStudentCount());

        $this->post('/classes/'.$this->class->id.'/students', ['form' => 'quick', 'display_name' => 'Student 36'])
            ->assertSessionHasErrors(['roster_cap' => self::FULL]);
        $this->post('/classes/'.$this->class->id.'/students', ['return_to' => 'daily', 'date' => '2026-10-21', 'display_name' => 'Student 36'])
            ->assertSessionHasErrors(['roster_cap' => self::FULL]);

        $this->assertSame(35, $this->class->activeStudentCount());
        $this->assertDatabaseMissing('students', ['display_name' => 'Student 36']);
    }

    public function test_a_csv_with_exactly_35_rows_imports_completely(): void
    {
        $page = $this->previewFile($this->csv(35))->assertOk()->assertDontSee('data-import-over-capacity', false);

        $this->post('/classes/'.$this->class->id.'/import/commit', ['token' => $this->token($page->getContent()), 'rows' => range(1, 35)])
            ->assertRedirect('/roster?class='.$this->class->id)->assertSessionHas('status', 'Imported 35 students.');

        $this->assertSame(35, $this->class->activeStudentCount());
    }

    public function test_a_csv_with_36_rows_flags_the_36th_before_saving_and_does_not_import_it(): void
    {
        $page = $this->previewFile($this->csv(36))->assertOk();
        $html = $page->getContent();

        $page->assertSee('Only 35 more students fit (0 of 35 active). Row 36 exceeds the limit and will not be imported.');
        $page->assertSee('Over capacity');
        $this->assertSame(0, Student::count(), 'The preview must not save anything.');
        preg_match_all('/<input type="checkbox"[^>]*>/', $html, $boxes);
        $this->assertCount(36, $boxes[0]);
        $this->assertStringContainsString('disabled', $boxes[0][35]);
        $this->assertStringNotContainsString('disabled', $boxes[0][34]);

        // Even a forged request that selects row 36 cannot import it.
        $this->post('/classes/'.$this->class->id.'/import/commit', ['token' => $this->token($html), 'rows' => range(1, 36)])
            ->assertSessionHas('status', 'Imported 35 students.');
        $this->assertSame(35, $this->class->activeStudentCount());
        $this->assertDatabaseMissing('students', ['display_name' => 'Student 36']);
    }

    public function test_pasted_text_follows_the_same_limit(): void
    {
        $ok = $this->post('/classes/'.$this->class->id.'/import/preview', ['pasted' => $this->names(35)])->assertOk();
        $ok->assertDontSee('data-import-over-capacity', false);
        $this->post('/classes/'.$this->class->id.'/import/commit', ['token' => $this->token($ok->getContent()), 'rows' => range(1, 35)]);
        $this->assertSame(35, $this->class->activeStudentCount());

        $other = SchoolClass::factory()->create(['name' => 'HNC 3C']);
        $over = $this->post('/classes/'.$other->id.'/import/preview', ['pasted' => $this->names(36)])->assertOk();
        $over->assertSee('Row 36 exceeds the limit and will not be imported.');
        $this->post('/classes/'.$other->id.'/import/commit', ['token' => $this->token($over->getContent()), 'rows' => range(1, 36)]);
        $this->assertSame(35, $other->activeStudentCount());
    }

    public function test_an_import_counts_the_students_already_in_the_class(): void
    {
        $this->seats(10);

        $page = $this->previewFile($this->csv(27))->assertOk();
        $page->assertSee('Only 25 more students fit (10 of 35 active). Rows 26–27 exceed the limit and will not be imported.');
        $page->assertSee('<strong>10 of 35</strong> active students', false);

        $this->post('/classes/'.$this->class->id.'/import/commit', ['token' => $this->token($page->getContent()), 'rows' => range(1, 27)]);
        $this->assertSame(35, $this->class->activeStudentCount());
    }

    public function test_a_full_class_flags_every_row_and_offers_nothing_to_import(): void
    {
        $this->seats(35);

        $page = $this->previewFile($this->csv(2))->assertOk();
        $page->assertSee(self::FULL.' Rows 1–2 will not be imported.');
        $page->assertSee('No valid rows to import.');
        $page->assertDontSee('Import selected students');
    }

    public function test_commit_recounts_inside_the_transaction_when_seats_were_taken_after_the_preview(): void
    {
        $page = $this->previewFile($this->csv(35))->assertOk();
        $this->seats(1);

        $this->post('/classes/'.$this->class->id.'/import/commit', ['token' => $this->token($page->getContent()), 'rows' => range(1, 35)])
            ->assertSessionHasErrors(['roster_cap']);

        $this->assertSame(1, Student::count(), 'Nothing from the import was saved.');
    }

    public function test_the_importer_service_rechecks_capacity_at_commit(): void
    {
        $importer = new RosterImporter;
        $preview = $importer->preview($this->class, null, $this->names(35));
        $this->seats(5);

        try {
            $importer->commit($this->class, $preview['token'], range(1, 35));
            $this->fail('ValidationException expected');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Only 30 more students fit (5 of 35 active)', $e->errors()['roster_cap'][0]);
        }
        $this->assertSame(5, Student::count());
    }

    public function test_restore_is_rejected_at_35_active_and_allowed_at_34(): void
    {
        $this->seats(35);
        $archived = Student::factory()->archived()->create(['school_class_id' => $this->class->id, 'display_name' => 'Old Student']);

        $this->post('/students/'.$archived->id.'/restore')->assertSessionHasErrors(['roster_cap' => self::FULL]);
        $this->assertNotNull($archived->fresh()->archived_at);

        Student::where('school_class_id', $this->class->id)->whereNull('archived_at')->first()->update(['archived_at' => now()]);
        $this->assertSame(34, $this->class->activeStudentCount());

        $this->post('/students/'.$archived->id.'/restore')->assertSessionDoesntHaveErrors();
        $this->assertNull($archived->fresh()->archived_at);
        $this->assertSame(35, $this->class->activeStudentCount());
    }

    public function test_archived_students_do_not_occupy_a_seat_and_archiving_frees_one(): void
    {
        $this->seats(30);
        Student::factory()->archived()->count(8)->create(['school_class_id' => $this->class->id]);

        $this->assertSame(30, $this->class->activeStudentCount());
        $this->assertSame(5, $this->class->remainingCapacity());
        $this->post('/classes/'.$this->class->id.'/students', ['display_name' => 'Fits Fine'])->assertSessionDoesntHaveErrors();

        $this->seats(4);
        $this->assertSame(0, $this->class->remainingCapacity());
        $this->post('/classes/'.$this->class->id.'/students', ['display_name' => 'No Seat'])->assertSessionHasErrors('roster_cap');

        $this->post('/students/'.Student::where('display_name', 'Fits Fine')->value('id').'/archive');
        $this->post('/classes/'.$this->class->id.'/students', ['display_name' => 'Seat Freed'])->assertSessionDoesntHaveErrors();
        $this->assertSame(35, $this->class->activeStudentCount());
    }

    public function test_the_limit_is_per_class_and_other_classes_are_not_affected(): void
    {
        $other = SchoolClass::factory()->create(['name' => 'HNC 3C']);
        $this->seats(35);
        $this->seats(3, $other);

        $this->post('/classes/'.$other->id.'/students', ['display_name' => 'Elsewhere'])->assertSessionDoesntHaveErrors();
        $this->assertSame(4, $other->activeStudentCount());
        $this->assertSame(35, $this->class->activeStudentCount());

        $foreign = Student::factory()->archived()->create(['school_class_id' => $other->id]);
        $this->post('/students/'.$foreign->id.'/restore')->assertSessionDoesntHaveErrors();
        $this->assertSame(35, $this->class->fresh()->activeStudentCount());
    }

    public function test_a_class_over_the_limit_keeps_every_student_and_blocks_additions(): void
    {
        $this->seats(38);
        $entry = ParticipationEntry::factory()->create(['school_class_id' => $this->class->id, 'student_id' => Student::first()->id]);
        $archived = Student::factory()->archived()->create(['school_class_id' => $this->class->id]);

        $this->post('/classes/'.$this->class->id.'/students', ['display_name' => 'Nope'])
            ->assertSessionHasErrors(['roster_cap' => 'This class is full: 38 of 35 active students. Archive a student to free a seat.']);
        $this->post('/students/'.$archived->id.'/restore')->assertSessionHasErrors('roster_cap');
        $this->assertSame(0, $this->class->remainingCapacity());

        $page = $this->previewFile($this->csv(1))->assertOk();
        $this->assertStringContainsString('will not be imported.', $page->getContent());

        $this->get('/roster?class='.$this->class->id)->assertOk()->assertSee('Over limit')->assertSee('Nothing was removed');

        $this->assertSame(38, $this->class->activeStudentCount(), 'No student is ever deleted to apply a limit.');
        $this->assertSame(39, Student::count());
        $this->assertNotNull(ParticipationEntry::find($entry->id));
        $this->assertSame(35, $this->class->fresh()->seatLimit());
    }

    public function test_the_capacity_meter_follows_the_limit(): void
    {
        $this->seats(12);

        $this->get('/roster?class='.$this->class->id)->assertOk()->assertSee('12 / 35 seats (34%)');
        $this->get('/daily?class='.$this->class->id)->assertOk()->assertSee('12/35 seats used');
    }
}
