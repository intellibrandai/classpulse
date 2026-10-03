<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentNote;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Runs only against the throwaway test DB (it rebuilds the schema itself, so it does not use RefreshDatabase).
 */
class NewSchemaMigrationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('classpulse_test', DB::connection()->getDatabaseName());
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    public function test_rolling_the_new_migrations_back_and_forward_keeps_existing_data(): void
    {
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'subject_description' => 'Food']);
        $student = Student::factory()->create(['school_class_id' => $class->id, 'display_name' => 'Alex Rivera', 'student_number' => 'S-1']);
        ParticipationEntry::factory()->create(['school_class_id' => $class->id, 'student_id' => $student->id, 'points' => 4]);

        Artisan::call('migrate:rollback', ['--step' => 6, '--force' => true]);

        foreach (['student_notes', 'academic_periods', 'report_comment_drafts'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertFalse(Schema::hasColumns('students', ['preferred_name']));
        $this->assertFalse(Schema::hasColumns('school_classes', ['title']));
        $this->assertSame('Alex Rivera', DB::table('students')->value('display_name'));
        $this->assertSame(4, (int) DB::table('participation_entries')->value('points'));

        Artisan::call('migrate', ['--force' => true]);

        $this->assertTrue(Schema::hasColumns('students', ['preferred_name', 'observations']));
        $this->assertTrue(Schema::hasColumns('school_classes', ['title', 'room', 'schedule']));
        $this->assertTrue(Schema::hasTable('student_notes'));
        $this->assertTrue(Schema::hasTable('academic_periods'));
        $this->assertSame('Alex Rivera', DB::table('students')->value('display_name'));
        $this->assertNull(DB::table('students')->value('preferred_name'));
        $this->assertNull(DB::table('school_classes')->value('room'));
        $this->assertSame('S-1', DB::table('students')->value('student_number'));
        $this->assertSame(4, (int) DB::table('participation_entries')->value('points'));
    }

    public function test_the_35_seat_migration_raises_only_caps_of_exactly_30_and_deletes_nothing(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        $now = now();
        foreach ([['HLS 3O', 30], ['HNC 3C', 28], ['HNL 2O', 25]] as [$name, $cap]) {
            $id = DB::table('school_classes')->insertGetId(['name' => $name, 'roster_cap' => $cap, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('students')->insert(['school_class_id' => $id, 'display_name' => 'Kept '.$name, 'created_at' => $now, 'updated_at' => $now]);
        }

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(['HLS 3O' => 35, 'HNC 3C' => 28, 'HNL 2O' => 25], DB::table('school_classes')->orderBy('id')->pluck('roster_cap', 'name')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(3, DB::table('students')->count());
        $id = DB::table('school_classes')->insertGetId(['name' => 'After', 'created_at' => $now, 'updated_at' => $now]);
        $this->assertSame(35, (int) DB::table('school_classes')->where('id', $id)->value('roster_cap'));
    }

    public function test_new_tables_enforce_their_constraints(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->create(['school_class_id' => $class->id]);
        StudentNote::create(['school_class_id' => $class->id, 'student_id' => $student->id, 'note_date' => '2026-10-19', 'body' => 'x']);

        try {
            StudentNote::create(['school_class_id' => $class->id, 'student_id' => $student->id, 'note_date' => '2026-10-19', 'body' => 'dup']);
            $this->fail('A second note for the same student and day must be rejected.');
        } catch (QueryException) {
            $this->assertSame(1, StudentNote::count());
        }

        $class->academicPeriods()->create(['kind' => 'q1', 'label' => 'Q1', 'starts_on' => '2026-09-08', 'ends_on' => '2026-11-13']);
        $this->expectException(QueryException::class);
        $class->academicPeriods()->create(['kind' => 'q1', 'label' => 'again', 'starts_on' => '2026-09-08', 'ends_on' => '2026-11-13']);
    }

    public function test_period_range_check_rejects_an_inverted_range(): void
    {
        $class = SchoolClass::factory()->create();

        $this->expectException(QueryException::class);
        $class->academicPeriods()->create(['kind' => 'q2', 'label' => 'Q2', 'starts_on' => '2026-11-14', 'ends_on' => '2026-11-13']);
    }
}
