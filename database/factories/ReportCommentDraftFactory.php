<?php

namespace Database\Factories;

use App\Models\ReportCommentDraft;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportCommentDraft>
 */
class ReportCommentDraftFactory extends Factory
{
    protected $model = ReportCommentDraft::class;

    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'student_id' => fn (array $attributes) => Student::factory()->create([
                'school_class_id' => $attributes['school_class_id'],
            ])->id,
            'period' => 'full',
            'body' => 'Participates steadily and supports classmates during group work.',
        ];
    }
}
