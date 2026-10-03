<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentHistoryTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    private Student $alex;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 10:00', 'America/Toronto'));
        $this->class = SchoolClass::factory()->create([
            'name' => 'HNL 2O', 'semester_start' => '2026-09-08', 'semester_end' => '2027-01-29',
        ]);
        $this->alex = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Alex Rivera']);
        $this->entry($this->alex, '2026-10-19', 'present', 3);
        $this->entry($this->alex, '2026-10-20', 'present', 0);
        $this->entry($this->alex, '2026-10-21', 'absent', null);
        $this->entry($this->alex, '2026-10-23', 'present', 5);
        $this->entry($this->alex, '2026-10-26', 'present', 2);
        $this->entry($this->alex, '2026-08-31', 'present', 9);

        $this->actingAs(User::factory()->create());
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
            'work_date' => $date, 'status' => $status, 'points' => $points, 'restore_points' => null,
        ]);
    }

    public function test_student_page_lists_dates_and_weekly_subtotals(): void
    {
        $response = $this->get('/students/'.$this->alex->id);

        $response->assertOk();
        $response->assertSee('Alex Rivera');
        $response->assertSee('HNL 2O');
        $response->assertSeeInOrder(['2026-10-19', 'Monday', 'Present', '3']);
        $response->assertSeeInOrder(['2026-10-21', 'Wednesday', 'Absent']);
        $response->assertDontSee('2026-08-31');
        $response->assertSeeInOrder(['Weekly subtotals', '2026-10-19', '8', '2026-10-26', '2']);
    }

    public function test_student_csv_lists_each_recorded_date(): void
    {
        $response = $this->get('/export/student/'.$this->alex->id.'.csv');

        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="classpulse-student-HNL-2O-Alex-Rivera-2026-09-08-to-2026-10-26-exported-2026-10-26.csv"');
        $this->assertSame(
            '"Date","Weekday","Status","Points"'."\n"
            .'"2026-10-19","Monday","Present",3'."\n"
            .'"2026-10-20","Tuesday","Present",0'."\n"
            .'"2026-10-21","Wednesday","Absent",'."\n"
            .'"2026-10-23","Friday","Present",5'."\n"
            .'"2026-10-26","Monday","Present",2'."\n",
            $response->getContent(),
        );
    }

    public function test_unknown_student_returns_404(): void
    {
        $this->get('/students/999999')->assertNotFound();
        $this->get('/export/student/999999.csv')->assertNotFound();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $this->get('/students/'.$this->alex->id)->assertRedirect('/login');
        $this->get('/export/student/'.$this->alex->id.'.csv')->assertRedirect('/login');
    }
}
