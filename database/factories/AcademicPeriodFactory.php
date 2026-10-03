<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicPeriod>
 */
class AcademicPeriodFactory extends Factory
{
    protected $model = AcademicPeriod::class;

    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'kind' => 'q1',
            'label' => 'Q1 / Midterm',
            'starts_on' => '2026-09-08',
            'ends_on' => '2026-11-13',
        ];
    }

    public function q2(): static
    {
        return $this->state(fn () => ['kind' => 'q2', 'label' => 'Q2 / Finals', 'starts_on' => '2026-11-16', 'ends_on' => '2027-01-29']);
    }
}
