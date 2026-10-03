<?php

namespace App\Http\Requests;

use App\Models\SchoolClass;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET (?period=), PUT and DELETE of report card comment drafts. The period is the `full|q1|q2` key; q1 and q2 only
 * exist when the class has that quarter configured. A PUT body is trimmed and must be 1-4000 characters
 * (a blank body is handled by the controller as "back to the generated template").
 */
class ReportCommentRequest extends FormRequest
{
    public const MAX_BODY = 4000;

    public const PERIODS = ['full', 'q1', 'q2'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $period = $this->route('period') ?? $this->query('period', 'full');
        $data = ['period' => is_string($period) && $period !== '' ? $period : 'full'];
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
        $rules = ['period' => ['required', 'string', 'in:'.implode(',', self::PERIODS)]];
        if ($this->isMethod('PUT')) {
            $rules['body'] = ['present', 'nullable', 'string', 'max:'.self::MAX_BODY];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'period.in' => 'The period must be full, q1 or q2.',
            'body.max' => 'A comment can have at most '.self::MAX_BODY.' characters.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $period = $this->input('period');
            $class = $this->route('class');
            if (! in_array($period, ['q1', 'q2'], true) || ! $class instanceof SchoolClass || $validator->errors()->has('period')) {
                return;
            }
            if (! $class->academicPeriods()->where('kind', $period)->exists()) {
                $validator->errors()->add('period', 'This class has no '.strtoupper($period).' period configured.');
            }
        }];
    }

    public function period(): string
    {
        return (string) $this->validated('period');
    }
}
