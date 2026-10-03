<?php

namespace App\Http\Requests;

use App\Models\SchoolClass;
use App\Support\AcademicPeriods;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Class details plus optional Q1/Q2 period settings (q1_label, q1_start, q1_end, q2_label, q2_start, q2_end).
 * Period fields are only applied when at least one of them is sent; blank start and end remove that period.
 */
class UpdateClassRequest extends FormRequest
{
    public const PERIOD_FIELDS = ['q1_label', 'q1_start', 'q1_end', 'q2_label', 'q2_start', 'q2_end'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('school_classes', 'name')->ignore($this->route('class'))],
            'title' => ['nullable', 'string', 'max:120'],
            'subject_description' => ['nullable', 'string', 'max:120'],
            'period_label' => ['nullable', 'string', 'max:40'],
            'room' => ['nullable', 'string', 'max:60'],
            'schedule' => ['nullable', 'string', 'max:80'],
            'roster_cap' => ['required', 'integer', 'between:1,'.config('classpulse.max_roster')],
            'semester_start' => ['nullable', 'date_format:Y-m-d'],
            'semester_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:semester_start'],
            'q1_label' => ['nullable', 'string', 'max:40'],
            'q1_start' => ['nullable', 'date_format:Y-m-d'],
            'q1_end' => ['nullable', 'date_format:Y-m-d'],
            'q2_label' => ['nullable', 'string', 'max:40'],
            'q2_start' => ['nullable', 'date_format:Y-m-d'],
            'q2_end' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Whether the request carries period settings that should be saved.
     */
    public function hasPeriodInput(): bool
    {
        foreach (self::PERIOD_FIELDS as $field) {
            if ($this->exists($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Class attributes only (no period fields).
     *
     * @return array<string, mixed>
     */
    public function classAttributes(): array
    {
        return array_diff_key($this->validated(), array_flip(self::PERIOD_FIELDS));
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var SchoolClass $class */
                $class = $this->route('class');

                if (! $validator->errors()->has('roster_cap')) {
                    $active = $class->activeStudentCount();
                    if ((int) $this->input('roster_cap') < $active) {
                        $validator->errors()->add(
                            'roster_cap',
                            "This class has {$active} active students; the cap cannot be lower.",
                        );
                    }
                }

                if ($validator->errors()->hasAny(['semester_start', 'semester_end', 'q1_start', 'q1_end', 'q2_start', 'q2_end'])) {
                    return;
                }

                $stored = $class->academicPeriods()->get()->keyBy('kind');
                $range = fn (string $kind): array => $this->hasPeriodInput()
                    ? ['start' => $this->input($kind.'_start'), 'end' => $this->input($kind.'_end')]
                    : ['start' => $stored->get($kind)?->starts_on->format('Y-m-d'), 'end' => $stored->get($kind)?->ends_on->format('Y-m-d')];

                $errors = AcademicPeriods::validate(
                    $this->exists('semester_start') ? $this->input('semester_start') : $class->semester_start?->format('Y-m-d'),
                    $this->exists('semester_end') ? $this->input('semester_end') : $class->semester_end?->format('Y-m-d'),
                    $range('q1'),
                    $range('q2'),
                );
                foreach ($errors as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }
}
