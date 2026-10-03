<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DestroyClassRequest extends FormRequest
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
            'confirm_name' => ['required', 'string'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('confirm_name')) {
                    return;
                }
                if ($this->input('confirm_name') !== $this->route('class')->name) {
                    $validator->errors()->add('confirm_name', 'Type the class name exactly to confirm.');
                }
            },
        ];
    }
}
