<?php

namespace Database\Factories;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParticipationEntry>
 */
class ParticipationEntryFactory extends Factory
{
    protected $model = ParticipationEntry::class;

    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'student_id' => fn (array $attributes) => Student::factory()->create([
                'school_class_id' => $attributes['school_class_id'],
            ])->id,
            'work_date' => '2026-10-19',
            'status' => 'present',
            'points' => 0,
            'restore_points' => null,
        ];
    }
}
