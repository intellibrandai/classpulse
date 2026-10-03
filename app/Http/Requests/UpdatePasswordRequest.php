<?php

namespace App\Http\Requests;

use Closure;

class UpdatePasswordRequest extends AccountCredentialRequest
{
    /** bcrypt ignores everything after the 72nd byte, so a longer password is refused instead of silently truncated. */
    public const BCRYPT_MAX_BYTES = 72;

    protected $errorBag = 'accountPassword';

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => [
                'required', 'string', 'min:'.config('classpulse.password_min_length'), 'max:255', 'different:current_password',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && strlen($value) > self::BCRYPT_MAX_BYTES) {
                        $fail('The new password must not be longer than '.self::BCRYPT_MAX_BYTES.' characters.');
                    }
                },
            ],
            'password_confirmation' => ['required', 'same:password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter your current password.',
            'current_password.current_password' => 'The current password is incorrect.',
            'password.required' => 'Enter a new password.',
            'password.min' => 'The new password must be at least '.config('classpulse.password_min_length').' characters.',
            'password.max' => 'The new password must not be longer than 255 characters.',
            'password.different' => 'Choose a new password that is different from the current one.',
            'password_confirmation.required' => 'Confirm your new password.',
            'password_confirmation.same' => 'The password confirmation does not match.',
        ];
    }
}
