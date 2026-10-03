<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', 'unique:school_classes,name'],
            'title' => ['nullable', 'string', 'max:120'],
            'subject_description' => ['nullable', 'string', 'max:120'],
            'period_label' => ['nullable', 'string', 'max:40'],
            'room' => ['nullable', 'string', 'max:60'],
            'schedule' => ['nullable', 'string', 'max:80'],
            'roster_cap' => ['required', 'integer', 'between:1,'.config('classpulse.max_roster')],
            'semester_start' => ['nullable', 'date_format:Y-m-d'],
            'semester_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:semester_start'],
        ];
    }
}
