<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private function makeClass(string $name = 'HNL 10', array $extra = []): int
    {
        return DB::table('school_classes')->insertGetId(array_merge([
            'name' => $name,
            'roster_cap' => 30, // an explicit non-default value on purpose
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    private function makeStudent(int $classId, string $name = 'Alex Rivera'): int
    {
        return DB::table('students')->insertGetId([
            'school_class_id' => $classId,
            'display_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function entry(int $classId, int $studentId, array $extra = []): array
    {
        return array_merge([
            'school_class_id' => $classId,
            'student_id' => $studentId,
            'work_date' => '2026-10-19',
            'status' => 'present',
            'points' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra);
    }

    public function test_rejects_entry_for_student_of_another_class(): void
    {
        $classA = $this->makeClass('HNL 10');
        $classB = $this->makeClass('HNL 11');
        $student = $this->makeStudent($classA);

        $this->expectException(QueryException::class);
        DB::table('participation_entries')->insert($this->entry($classB, $student));
    }

    public function test_rejects_duplicate_entry_for_same_class_student_and_date(): void
    {
        $class = $this->makeClass();
        $student = $this->makeStudent($class);
        DB::table('participation_entries')->insert($this->entry($class, $student));

        $this->expectException(QueryException::class);
        DB::table('participation_entries')->insert($this->entry($class, $student));
    }

    public function test_rejects_points_above_99(): void
    {
        $class = $this->makeClass();
        $student = $this->makeStudent($class);

        $this->expectException(QueryException::class);
        DB::table('participation_entries')->insert($this->entry($class, $student, ['points' => 100]));
    }

    public function test_rejects_present_entry_without_points(): void
    {
        $class = $this->makeClass();
        $student = $this->makeStudent($class);

        $this->expectException(QueryException::class);
        DB::table('participation_entries')->insert($this->entry($class, $student, ['points' => null]));
    }

    public function test_accepts_absent_entry_without_points(): void
    {
        $class = $this->makeClass();
        $student = $this->makeStudent($class);
        DB::table('participation_entries')->insert($this->entry($class, $student, ['status' => 'absent', 'points' => null]));

        $this->assertSame(1, DB::table('participation_entries')->count());
    }

    public function test_rejects_roster_cap_above_30(): void
    {
        // The limit is config('classpulse.max_roster'); the CHECK constraint must agree with it (see the 2026_10_01 migration).
        $this->assertSame(35, config('classpulse.max_roster'));
        $this->makeClass('HNL 11', ['roster_cap' => config('classpulse.max_roster')]);
        $this->expectException(QueryException::class);
        $this->makeClass('HNL 12', ['roster_cap' => config('classpulse.max_roster') + 1]);
    }

    public function test_new_classes_default_to_the_central_limit(): void
    {
        $id = DB::table('school_classes')->insertGetId(['name' => 'HNL 14', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(config('classpulse.max_roster'), (int) DB::table('school_classes')->where('id', $id)->value('roster_cap'));
    }

    public function test_rejects_semester_end_before_start(): void
    {
        $this->expectException(QueryException::class);
        $this->makeClass('HNL 13', ['semester_start' => '2026-12-01', 'semester_end' => '2026-09-01']);
    }

    public function test_deleting_a_class_cascades_to_all_children(): void
    {
        $class = $this->makeClass();
        $student = $this->makeStudent($class);
        DB::table('participation_entries')->insert($this->entry($class, $student));
        $seq = DB::table('participation_operations')->insertGetId([
            'op_id' => '11111111-1111-4111-8111-111111111111',
            'school_class_id' => $class,
            'work_date' => '2026-10-19',
            'kind' => 'increment',
            'created_at' => now(),
        ]);
        DB::table('participation_events')->insert([
            'operation_seq' => $seq,
            'school_class_id' => $class,
            'student_id' => $student,
            'before_exists' => false,
            'created_at' => now(),
        ]);

        DB::table('school_classes')->where('id', $class)->delete();

        $this->assertSame(0, DB::table('students')->where('school_class_id', $class)->count());
        $this->assertSame(0, DB::table('participation_entries')->where('school_class_id', $class)->count());
        $this->assertSame(0, DB::table('participation_operations')->where('school_class_id', $class)->count());
        $this->assertSame(0, DB::table('participation_events')->where('school_class_id', $class)->count());
    }
}
