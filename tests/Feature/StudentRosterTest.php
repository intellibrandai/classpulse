<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentRosterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_teacher_adds_a_student(): void
    {
        $class = SchoolClass::factory()->create();

        $this->post('/classes/'.$class->id.'/students', ['display_name' => '  Alex Rivera ', 'student_number' => '1001'])
            ->assertRedirect('/roster?class='.$class->id);

        $student = Student::firstOrFail();
        $this->assertSame('Alex Rivera', $student->display_name);
        $this->assertSame($class->id, $student->school_class_id);
        $this->assertNull($student->archived_at);
        $this->post('/classes/'.$class->id.'/students', ['display_name' => ''])->assertSessionHasErrors('display_name');
        $this->post('/classes/'.$class->id.'/students', ['display_name' => str_repeat('a', 121)])->assertSessionHasErrors('display_name');
    }

    public function test_cap_blocks_the_31st_active_student(): void
    {
        // The name is historical (the limit used to be 30); the limit is config('classpulse.max_roster') = 35 now.
        $class = SchoolClass::factory()->create();
        Student::factory()->count(35)->create(['school_class_id' => $class->id]);
        $archived = Student::factory()->archived()->create(['school_class_id' => $class->id]);
        $message = 'This class is full: 35 of 35 active students. Archive a student to free a seat.';

        $this->post('/classes/'.$class->id.'/students', ['display_name' => 'Jordan Lee'])
            ->assertSessionHasErrors(['roster_cap' => $message]);
        $this->post('/students/'.$archived->id.'/restore')
            ->assertSessionHasErrors(['roster_cap' => $message]);

        $this->assertSame(35, $class->activeStudentCount());
        $this->assertNotNull($archived->fresh()->archived_at);
    }

    public function test_archive_keeps_entries_and_restore_clears_it(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->create(['school_class_id' => $class->id]);
        ParticipationEntry::factory()->create(['school_class_id' => $class->id, 'student_id' => $student->id]);

        $this->post('/students/'.$student->id.'/archive')->assertRedirect('/roster?class='.$class->id);
        $this->assertNotNull($student->fresh()->archived_at);
        $this->assertSame(1, ParticipationEntry::count());

        $this->post('/students/'.$student->id.'/restore')->assertRedirect('/roster?class='.$class->id);
        $this->assertNull($student->fresh()->archived_at);
    }

    public function test_rename_keeps_the_id(): void
    {
        $student = Student::factory()->create(['display_name' => 'Robin Sky']);

        $this->put('/students/'.$student->id, ['display_name' => 'Robin S. Sky', 'student_number' => '7'])->assertRedirect();

        $fresh = $student->fresh();
        $this->assertSame($student->id, $fresh->id);
        $this->assertSame('Robin S. Sky', $fresh->display_name);
        $this->assertSame('7', $fresh->student_number);
    }

    public function test_roster_page_lists_active_and_archived_separately(): void
    {
        $class = SchoolClass::factory()->create(['roster_cap' => 35]);
        Student::factory()->create(['school_class_id' => $class->id, 'display_name' => 'Alex Rivera']);
        Student::factory()->archived()->create(['school_class_id' => $class->id, 'display_name' => 'Jordan Lee']);

        $html = $this->get('/roster?class='.$class->id)->assertOk()->assertSee('1 / 35')->getContent();

        $activePos = strpos($html, 'Active students');
        $archivedPos = strpos($html, 'Archived students');
        $this->assertLessThan($archivedPos, $activePos);
        $this->assertLessThan($archivedPos, strpos($html, 'Alex Rivera', $activePos));
        $this->assertGreaterThan($archivedPos, strpos($html, 'Jordan Lee', $archivedPos - 1));
        $this->assertStringContainsString('Archive Alex Rivera?', $html);
    }

    public function test_empty_class_shows_import_prompt(): void
    {
        $class = SchoolClass::factory()->create();

        $this->get('/roster?class='.$class->id)
            ->assertSee('No students yet')
            ->assertSee('import a CSV')
            ->assertSee('/classes/'.$class->id.'/import');
    }

    public function test_unknown_student_ids_return_404(): void
    {
        $this->put('/students/9999', ['display_name' => 'X'])->assertNotFound();
        $this->post('/students/9999/archive')->assertNotFound();
        $this->post('/students/9999/restore')->assertNotFound();
        $this->post('/classes/9999/students', ['display_name' => 'X'])->assertNotFound();
    }
}
