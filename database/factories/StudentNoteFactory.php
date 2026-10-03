<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentNote>
 */
class StudentNoteFactory extends Factory
{
    protected $model = StudentNote::class;

    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'student_id' => fn (array $attributes) => Student::factory()->create([
                'school_class_id' => $attributes['school_class_id'],
            ])->id,
            'note_date' => '2026-10-19',
            'body' => 'Asked a thoughtful follow-up question.',
        ];
    }
}
