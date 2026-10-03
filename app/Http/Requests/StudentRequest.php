<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('display_name'))) {
            $this->merge(['display_name' => trim($this->input('display_name'))]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'min:1', 'max:120'],
            'preferred_name' => ['nullable', 'string', 'max:80'],
            'student_number' => ['nullable', 'string', 'max:40'],
            'observations' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
