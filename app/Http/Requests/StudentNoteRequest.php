<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT and DELETE of one student note. The date comes from the route; an empty PUT body deletes the note.
 */
class StudentNoteRequest extends FormRequest
{
    public const MAX_BODY = 2000;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = ['note_date' => $this->route('date')];
        if (is_string($this->input('body'))) {
            $data['body'] = trim($this->input('body'));
        }
        $this->merge($data);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = ['note_date' => ['required', 'date_format:Y-m-d']];
        if ($this->isMethod('PUT')) {
            $rules['body'] = ['present', 'nullable', 'string', 'max:'.self::MAX_BODY];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'note_date.date_format' => 'The date is not a valid calendar date.',
            'body.max' => 'A note can have at most '.self::MAX_BODY.' characters.',
        ];
    }
}
