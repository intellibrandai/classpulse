<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolClass>
 */
class SchoolClassFactory extends Factory
{
    protected $model = SchoolClass::class;

    public function definition(): array
    {
        return [
            'name' => 'HNL '.fake()->unique()->numberBetween(10, 99),
            'title' => null,
            'subject_description' => null,
            'period_label' => null,
            'room' => null,
            'schedule' => null,
            'roster_cap' => config('classpulse.max_roster'),
            'semester_start' => null,
            'semester_end' => null,
        ];
    }
}
