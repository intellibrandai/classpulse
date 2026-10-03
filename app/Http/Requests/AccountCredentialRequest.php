<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Shared base of the two Account-settings requests: the signed-in user is read from the session (never from the form),
 * every failed attempt (wrong current password or any validation error) counts against ONE limiter shared by both
 * forms, so the current password cannot be guessed by alternating them. The limiter is cleared by the controller on success.
 */
abstract class AccountCredentialRequest extends FormRequest
{
    /** Failed attempts are redirected back to the Account group of the Roster page. */
    protected $redirect = '/roster#account';

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $key = self::throttleKey($this);

        if (RateLimiter::tooManyAttempts($key, (int) config('classpulse.account_max_attempts'))) {
            $seconds = RateLimiter::availableIn($key);

            throw new HttpResponseException(response()->view('errors.429', [
                'retryMessage' => "Too many failed attempts. Wait {$seconds} seconds, then try again from Account settings.",
            ], 429, ['Retry-After' => (string) $seconds]));
        }

        return true;
    }

    public static function throttleKey(Request $request): string
    {
        return 'account-credentials|'.$request->user()?->getAuthIdentifier().'|'.$request->ip();
    }

    protected function failedValidation(Validator $validator): void
    {
        RateLimiter::hit(self::throttleKey($this), (int) config('classpulse.account_decay_seconds'));

        parent::failedValidation($validator);
    }
}
