<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemesterReportTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 10:00', 'America/Toronto'));
        $this->class = SchoolClass::factory()->create([
            'name' => 'HNL 2O', 'semester_start' => '2026-09-08', 'semester_end' => '2027-01-29',
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
            'school_class_id' => $this->class->id, 'display_name' => $name,
            'student_number' => $number, 'archived_at' => $archivedAt,
        ]);
    }

    private function entry(Student $student, string $date, string $status, ?int $points): void
    {
        ParticipationEntry::factory()->create([
            'school_class_id' => $this->class->id, 'student_id' => $student->id,
            'work_date' => $date, 'status' => $status, 'points' => $points, 'restore_points' => null,
        ]);
    }

    public function test_semester_page_lists_students_with_totals_and_average(): void
    {
        $alex = $this->student('Alex Rivera', 'S-1001');
        $this->student('Jordan Lee');
        $morgan = $this->student('Morgan Diaz', null, '2026-10-24 12:00:00');
        $this->student('Quinn Old', null, '2026-09-30 12:00:00');
        $this->entry($alex, '2026-09-14', 'present', 3);
        $this->entry($alex, '2026-09-15', 'present', 0);
        $this->entry($alex, '2026-09-16', 'absent', null);
        $this->entry($alex, '2026-10-23', 'present', 5);
        $this->entry($morgan, '2026-10-21', 'present', 4);

        $response = $this->get('/semester?class='.$this->class->id);

        $response->assertOk();
        $response->assertSeeInOrder(['Alex Rivera', 'Jordan Lee', 'Morgan Diaz', 'Archived']);
        $response->assertDontSee('Quinn Old');
        $response->assertSee('Sep 8, 2026 – Jan 29, 2027');
        $response->assertSee('2.67');
        $response->assertSee('No data');
        $response->assertSee('Master Cumulative Roster');
        $response->assertSee('href="'.url('/students/'.$alex->id).'"', false);
    }

    public function test_class_without_entries_shows_empty_state(): void
    {
        $this->student('Jordan Lee');

        $this->get('/semester?class='.$this->class->id)->assertOk()->assertSee('No data: nothing has been recorded in Full Semester yet.');
    }

    public function test_semester_csv_header_and_empty_student_number(): void
    {
        $alex = $this->student('Alex Rivera', 'S-1001');
        $jordan = $this->student('Jordan Lee');
        $this->entry($alex, '2026-09-14', 'present', 3);
        $this->entry($alex, '2026-09-15', 'absent', null);
        $this->entry($jordan, '2026-09-14', 'present', 0);

        $response = $this->get('/export/semester.csv?class='.$this->class->id);

        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="classpulse-semester-HNL-2O-2026-09-08-to-2026-10-26-exported-2026-10-26.csv"');
        $this->assertSame(
            '"Student","Student number","Total points","Present days recorded","Absences","Days recorded","Participation days","Average per present day"'."\n"
            .'"Alex Rivera","S-1001",3,1,1,2,1,"3.00"'."\n"
            .'"Jordan Lee","",0,1,0,1,0,"0.00"'."\n",
            $response->getContent(),
        );
    }

    public function test_guests_are_redirected_to_login(): void
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $this->get('/semester')->assertRedirect('/login');
        $this->get('/export/semester.csv')->assertRedirect('/login');
    }
}
