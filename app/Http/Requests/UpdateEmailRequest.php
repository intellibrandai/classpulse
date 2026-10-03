<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateEmailRequest extends AccountCredentialRequest
{
    /** Dedicated error bag: never collides with the other forms on the Roster page. */
    protected $errorBag = 'accountEmail';

    protected function prepareForValidation(): void
    {
        $email = $this->input('new_email');

        if (is_string($email)) {
            $this->merge(['new_email' => Str::lower(trim($email))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'new_email' => [
                'required', 'string', 'email:rfc', 'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                    if (is_string($value) && Str::lower((string) $user->email) === $value) {
                        $fail('Enter an email address that is different from your current one.');
                    }
                },
                Rule::unique('users', 'email')->ignore($user->getAuthIdentifier()),
            ],
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'new_email.required' => 'Enter your new email address.',
            'new_email.email' => 'Enter a valid email address.',
            'new_email.max' => 'The email address must not be longer than 255 characters.',
            'new_email.unique' => 'That email is already used by another account.',
            'current_password.required' => 'Enter your current password.',
            'current_password.current_password' => 'The current password is incorrect.',
        ];
    }
}
