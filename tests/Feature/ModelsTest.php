<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\ParticipationOperation;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_factory_defaults(): void
    {
        $class = SchoolClass::factory()->create();

        $this->assertSame(35, $class->fresh()->roster_cap);
        $this->assertSame(config('classpulse.max_roster'), $class->fresh()->roster_cap);
        $this->assertStringStartsWith('HNL ', $class->name);
    }

    public function test_active_student_count_and_remaining_capacity(): void
    {
        $class = SchoolClass::factory()->create(['roster_cap' => 35]);
        $active = Student::factory()->count(3)->create(['school_class_id' => $class->id]);
        Student::factory()->archived()->create(['school_class_id' => $class->id]);

        $this->assertSame(3, $class->activeStudentCount());
        $this->assertSame(32, $class->remainingCapacity());
        $this->assertEqualsCanonicalizing($active->pluck('id')->all(), $class->activeStudents()->pluck('id')->all());
        $this->assertCount(4, $class->students);
    }

    public function test_active_scope_and_is_archived(): void
    {
        $class = SchoolClass::factory()->create();
        $active = Student::factory()->create(['school_class_id' => $class->id]);
        $archived = Student::factory()->archived()->create(['school_class_id' => $class->id]);

        $ids = Student::active()->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($archived->id, $ids);
        $this->assertFalse($active->isArchived());
        $this->assertTrue($archived->isArchived());
        $this->assertTrue($active->schoolClass->is($class));
    }

    public function test_archived_state_sets_archived_at_and_leaves_student_number_null(): void
    {
        $student = Student::factory()->archived()->create();

        $this->assertNotNull($student->archived_at);
        $this->assertNull($student->student_number);
    }

    public function test_entry_factory_creates_present_zero_entry_for_a_student_of_the_same_class(): void
    {
        $entry = ParticipationEntry::factory()->create();

        $this->assertSame('present', $entry->status);
        $this->assertSame(0, $entry->points);
        $this->assertSame($entry->school_class_id, Student::find($entry->student_id)->school_class_id);
    }

    public function test_operation_uses_seq_key_and_has_no_updated_at(): void
    {
        $class = SchoolClass::factory()->create();
        $operation = ParticipationOperation::create([
            'op_id' => '22222222-2222-4222-8222-222222222222',
            'school_class_id' => $class->id,
            'work_date' => '2026-10-19',
            'kind' => 'increment',
        ]);

        $this->assertIsInt($operation->seq);
        $this->assertNotNull($operation->created_at);
        $this->assertArrayNotHasKey('updated_at', $operation->getAttributes());
    }
}
