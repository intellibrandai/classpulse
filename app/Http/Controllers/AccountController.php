<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountCredentialRequest;
use App\Http\Requests\UpdateEmailRequest;
use App\Http\Requests\UpdatePasswordRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** Account settings for the signed-in user: change email, change password. Nothing here reads a user id from the form. */
class AccountController extends Controller
{
    public function updateEmail(UpdateEmailRequest $request): RedirectResponse
    {
        $user = $request->user();
        $old = (string) $user->email;
        $new = (string) $request->validated('new_email');

        try {
            $user->forceFill(['email' => $new])->save();
        } catch (UniqueConstraintViolationException) {
            // Lost a race against another writer between validation and save: same message as the validator.
            RateLimiter::hit(AccountCredentialRequest::throttleKey($request), (int) config('classpulse.account_decay_seconds'));

            return redirect('/roster#account')->withErrors(['new_email' => 'That email is already used by another account.'], 'accountEmail');
        }

        RateLimiter::clear(AccountCredentialRequest::throttleKey($request));

        // The old and new address are not secrets: account.js uses them to refresh "Remember my email" in this browser.
        return redirect('/roster#account')->with('account_email_changed', ['old' => $old, 'new' => $new]);
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        $key = AccountCredentialRequest::throttleKey($request);

        DB::transaction(function () use ($user, $request): void {
            $user->forceFill([
                'password' => Hash::make((string) $request->validated('password')),
                'remember_token' => Str::random(60),
            ])->save();

            // End EVERY session of this user (the current one included), whatever the browser or device.
            DB::connection(config('session.connection'))->table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->delete();
        });

        RateLimiter::clear($key);
        $this->signOut($request);

        return redirect('/login')->with('status', 'Your password was changed. Please sign in again.');
    }

    private function signOut(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
