<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\CsvWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeeklyReportTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 10:00', 'America/Toronto'));

        $this->class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'roster_cap' => 30]);
        $alex = $this->student('Alex Rivera', 'S-1001');
        $jordan = $this->student('Jordan Lee');
        $robin = $this->student('=Robin Sky');
        $morgan = $this->student('Morgan Diaz', null, '2026-10-24 12:00:00');

        $this->entry($alex, '2026-10-19', 'present', 3);
        $this->entry($alex, '2026-10-20', 'present', 0);
        $this->entry($alex, '2026-10-21', 'absent', null);
        $this->entry($alex, '2026-10-23', 'present', 5);
        $this->entry($robin, '2026-10-19', 'present', 1);
        $this->entry($robin, '2026-10-20', 'present', 2);
        $this->entry($morgan, '2026-10-21', 'present', 4);

        $other = SchoolClass::factory()->create(['name' => 'HNC 3C']);
        $casey = Student::factory()->create(['school_class_id' => $other->id, 'display_name' => 'Casey Moon']);
        ParticipationEntry::factory()->create([
            'school_class_id' => $other->id, 'student_id' => $casey->id,
            'work_date' => '2026-10-19', 'status' => 'present', 'points' => 7,
        ]);

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function student(string $name, ?string $number = null, ?string $archivedAt = null): Student
    {
        return Student::factory()->create([
            'school_class_id' => $this->class->id,
            'display_name' => $name,
            'student_number' => $number,
            'archived_at' => $archivedAt,
        ]);
    }

    private function entry(Student $student, string $date, string $status, ?int $points): void
    {
        ParticipationEntry::factory()->create([
            'school_class_id' => $this->class->id,
            'student_id' => $student->id,
            'work_date' => $date,
            'status' => $status,
            'points' => $points,
            'restore_points' => null,
        ]);
    }

    public function test_weekly_csv_matches_fixture_byte_for_byte(): void
    {
        $response = $this->get('/export/weekly.csv?class='.$this->class->id.'&week=2026-10-19');

        $response->assertOk();
        $this->assertSame(file_get_contents(base_path('tests/Fixtures/weekly-export.csv')), $response->getContent());
    }

    public function test_weekly_csv_headers_and_filename(): void
    {
        $response = $this->get('/export/weekly.csv?class='.$this->class->id.'&week=2026-10-19');

        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="classpulse-weekly-HNL-2O-2026-10-19-exported-2026-10-26.csv"');
    }

    public function test_weekly_page_lists_active_and_archived_students_with_heat_classes(): void
    {
        $response = $this->get('/weekly?class='.$this->class->id.'&week=2026-10-19');

        $response->assertOk();
        $response->assertSeeInOrder(['Alex Rivera', 'Jordan Lee', 'Morgan Diaz', 'Archived']);
        $response->assertDontSee('Casey Moon');
        // Each recorded cell shows its value as text with a heat class; the state is also spelled out for readers.
        $response->assertSee('class="wm-cell heat-mid" data-cell', false);
        $response->assertSee('class="wm-cell heat-absent" data-cell', false);
        $response->assertSee('class="wm-cell heat-0" data-cell', false);
        $response->assertSee('class="wm-cell heat-low" data-cell', false);
        $response->assertSee('Alex Rivera, Mon Oct 19: Present, 3 points. Open editor');
        $response->assertSee('Alex Rivera, Wed Oct 21: Absent. Open editor');
        // Not recorded is a dashed dash with no heat colour, distinct from a recorded 0.
        $response->assertSee('class="wm-cell wm-cell-none" data-cell', false);
        $response->assertSee('Jordan Lee, Mon Oct 19: Not recorded. Open editor');
        $response->assertSee('aria-label="Legend"', false);
        $response->assertSee('Previous week');
        $response->assertSee('Next week');
        $response->assertSee('href="'.url('/students/'.Student::where('display_name', 'Alex Rivera')->value('id')).'"', false);
        $response->assertSee('Export CSV');
        $response->assertSee('Class weekly avg');
    }

    public function test_archived_student_without_entries_that_week_is_hidden(): void
    {
        $response = $this->get('/weekly?class='.$this->class->id.'&week=2026-10-26');

        $response->assertOk();
        $response->assertDontSee('Morgan Diaz');
        $response->assertSee('No data');
    }

    public function test_non_monday_week_redirects_to_its_monday(): void
    {
        $this->get('/weekly?class='.$this->class->id.'&week=2026-10-21')
            ->assertRedirect(url('/weekly?class='.$this->class->id.'&week=2026-10-19'));
    }

    public function test_csv_writer_sanitises_dangerous_prefixes_and_quotes(): void
    {
        foreach (['=1+1', '+1', '-1', '@sum', "\tx", "\rx"] as $text) {
            $this->assertSame('"\''.$text."\"\n", CsvWriter::line([$text]));
        }
        $this->assertSame("\"say \"\"hi\"\"\",7,,\"\"\n", CsvWriter::line(['say "hi"', 7, null, '']));
        $this->assertSame('classpulse-weekly-HNL-2O-2026-10-19-exported-2026-10-26.csv', CsvWriter::filename('weekly', 'HNL 2O', '2026-10-19', '2026-10-26'));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $this->get('/weekly')->assertRedirect('/login');
        $this->get('/export/weekly.csv')->assertRedirect('/login');
    }
}
