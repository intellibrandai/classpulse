<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DayOperationRequest extends FormRequest
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
            'op_id' => ['required', 'uuid'],
            'kind' => ['required', 'in:increment,decrement,set_zero,set_points,clear,absent_on,absent_off,zero_remaining,reset_day,undo'],
            'student_id' => ['required_if:kind,increment,decrement,set_zero,set_points,clear,absent_on,absent_off', 'nullable', 'integer'],
            // Absolute value for set_points; the 0..99 bounds are business rules (points_min / points_max) in the service.
            'points' => ['required_if:kind,set_points', 'nullable', 'integer', 'between:-9999,9999'],
        ];
    }
}
