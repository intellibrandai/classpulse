<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'display_name' => fake()->firstName().' '.fake()->lastName(),
            'preferred_name' => null,
            'student_number' => null,
            'observations' => null,
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['archived_at' => now()]);
    }
}
