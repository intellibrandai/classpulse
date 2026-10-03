<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Optional ?period= of the Semester Analytics endpoints. An unknown key is not an error: it falls back to Full Semester.
 */
class SemesterPeriodRequest extends FormRequest
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
        return ['period' => ['nullable', 'string', 'max:16']];
    }

    public function periodKey(): ?string
    {
        $key = $this->validated('period');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
