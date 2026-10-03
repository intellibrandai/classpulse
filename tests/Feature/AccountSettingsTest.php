<?php

namespace Tests\Feature;

use App\Http\Requests\UpdatePasswordRequest;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    private const NEW_PASSWORD = 'Sentinel-Pass-9f3a77c1!';

    private function teacher(string $email = 'teacher@classpulse.test'): User
    {
        return User::factory()->create(['email' => $email, 'password' => Hash::make(self::PASSWORD)]);
    }

    private function signedIn(): User
    {
        $user = $this->teacher();
        $this->actingAs($user);

        return $user;
    }

    /** @return array<string, string> */
    private function emailForm(string $new, string $password = self::PASSWORD): array
    {
        return ['new_email' => $new, 'current_password' => $password];
    }

    /** @return array<string, string> */
    private function passwordForm(string $new = self::NEW_PASSWORD, ?string $confirm = null, string $current = self::PASSWORD): array
    {
        return ['current_password' => $current, 'password' => $new, 'password_confirmation' => $confirm ?? $new];
    }

    // ---- access and wiring ---------------------------------------------------------------------------------------

    public function test_guests_are_redirected_from_both_account_endpoints(): void
    {
        $this->post(route('account.email'), $this->emailForm('x@example.test'))->assertRedirect('/login');
        $this->post(route('account.password'), $this->passwordForm())->assertRedirect('/login');
    }

    public function test_both_routes_are_post_only_named_and_sit_behind_auth_and_csrf(): void
    {
        foreach (['account.email' => 'account/email', 'account.password' => 'account/password'] as $name => $uri) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertSame($uri, $route->uri());
            $this->assertSame(['POST'], $route->methods());
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware);
            $this->assertContains('web', $middleware);
        }
        $this->assertContains(PreventRequestForgery::class, app(Kernel::class)->getMiddlewareGroups()['web']);
        $this->signedIn();
        $this->get('/account/password')->assertStatus(405);
    }

    // ---- wrong current password ----------------------------------------------------------------------------------

    public function test_wrong_current_password_blocks_both_changes_and_changes_nothing(): void
    {
        $user = $this->signedIn();
        $hash = $user->password;

        $this->post(route('account.email'), $this->emailForm('new@example.test', 'not the password'))
            ->assertRedirect('/roster#account')
            ->assertSessionHasErrors(['current_password' => 'The current password is incorrect.'], null, 'accountEmail');
        $this->post(route('account.password'), $this->passwordForm(current: 'not the password'))
            ->assertRedirect('/roster#account')
            ->assertSessionHasErrors(['current_password' => 'The current password is incorrect.'], null, 'accountPassword');

        $fresh = $user->fresh();
        $this->assertSame('teacher@classpulse.test', $fresh->email);
        $this->assertSame($hash, $fresh->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $fresh->password));
        $this->assertAuthenticatedAs($fresh);
    }

    // ---- change email --------------------------------------------------------------------------------------------

    public function test_email_change_succeeds_normalizes_case_keeps_the_session_and_changes_only_the_email(): void
    {
        $user = $this->signedIn();
        $hash = $user->password;

        $this->post(route('account.email'), $this->emailForm('  New.Teacher@Example.TEST  '))
            ->assertRedirect('/roster#account')
            ->assertSessionHas('account_email_changed', ['old' => 'teacher@classpulse.test', 'new' => 'new.teacher@example.test']);

        $fresh = $user->fresh();
        $this->assertSame('new.teacher@example.test', $fresh->email);
        $this->assertSame($hash, $fresh->password);
        $this->assertAuthenticatedAs($fresh);
        $this->assertSame(1, User::count());

        $html = $this->get('/roster')->assertOk()->getContent();
        $this->assertStringContainsString('Your email was changed to new.teacher@example.test.', $html);
        $this->assertStringContainsString('data-old-email="teacher@classpulse.test"', $html);
        $this->assertStringContainsString('data-new-email="new.teacher@example.test"', $html);
    }

    public function test_the_email_flash_is_escaped(): void
    {
        $this->signedIn();
        $this->post(route('account.email'), $this->emailForm("o'brien@example.test"));
        $html = $this->get('/roster')->getContent();

        $this->assertStringContainsString('Your email was changed to o&#039;brien@example.test.', $html);
        $this->assertStringNotContainsString("changed to o'brien", $html);
    }

    public function test_the_changed_email_can_sign_in_and_the_old_one_cannot(): void
    {
        $this->signedIn();
        $this->post(route('account.email'), $this->emailForm('fresh@example.test'));
        $this->post('/logout');

        $this->post('/login', ['email' => 'teacher@classpulse.test', 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'fresh@example.test', 'password' => self::PASSWORD])->assertRedirect('/daily');
        $this->assertAuthenticated();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badEmails(): array
    {
        return [
            'empty' => ['', 'Enter your new email address.'],
            'no at sign' => ['not-an-email', 'Enter a valid email address.'],
            'two at signs' => ['a@@example.test', 'Enter a valid email address.'],
            'spaces inside' => ['a b@example.test', 'Enter a valid email address.'],
            'too long' => [str_repeat('a', 250).'@example.test', 'The email address must not be longer than 255 characters.'],
            'same as current' => ['teacher@classpulse.test', 'Enter an email address that is different from your current one.'],
            'same as current, other case' => ['TEACHER@ClassPulse.Test', 'Enter an email address that is different from your current one.'],
            'taken' => ['other@example.test', 'That email is already used by another account.'],
            'taken, other case' => ['Other@Example.TEST', 'That email is already used by another account.'],
        ];
    }

    /**
     * @dataProvider badEmails
     */
    #[DataProvider('badEmails')]
    public function test_bad_new_emails_are_rejected_with_a_clear_message_and_nothing_changes(string $new, string $message): void
    {
        $user = $this->signedIn();
        $this->teacher('other@example.test');

        $this->from('/roster')->post(route('account.email'), $this->emailForm($new))
            ->assertRedirect('/roster#account')
            ->assertSessionHasErrors(['new_email' => $message], null, 'accountEmail');

        $this->assertSame('teacher@classpulse.test', $user->fresh()->email);
        $this->assertSame(2, User::count());
    }

    public function test_email_form_errors_use_their_own_bag_and_never_reach_the_default_bag(): void
    {
        $this->signedIn();
        $this->post(route('account.email'), $this->emailForm('nope'))
            ->assertSessionHasErrors('new_email', null, 'accountEmail')
            ->assertSessionDoesntHaveErrors(['new_email', 'current_password'], '', 'default')
            ->assertSessionDoesntHaveErrors(['new_email', 'current_password'], '', 'accountPassword');
    }

    // ---- change password -----------------------------------------------------------------------------------------

    public function test_password_change_succeeds_signs_everyone_out_and_the_new_password_works(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->teacher();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/daily');
        $current = session()->getId();
        $this->assertDatabaseHas('sessions', ['id' => $current, 'user_id' => $user->id]);

        // A second browser of the same user, and a session of another user that must survive.
        $other = $this->teacher('other@example.test');
        foreach ([['second-browser-session', $user->id], ['someone-elses-session', $other->id]] as [$id, $owner]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);
        }
        $oldToken = $user->fresh()->remember_token;

        $response = $this->withCookie(config('session.cookie'), $current)
            ->post(route('account.password'), $this->passwordForm());

        $response->assertRedirect('/login');
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'someone-elses-session', 'user_id' => $other->id]);
        $this->assertGuest();
        $this->assertNotSame($oldToken, $user->fresh()->remember_token);

        $login = $this->get('/login')->assertOk();
        $login->assertSee('Your password was changed. Please sign in again.');
        $this->assertMatchesRegularExpression('/<p class="form-success login-status" role="status">/', $login->getContent());

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => self::NEW_PASSWORD])->assertRedirect('/daily');
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_the_status_message_shows_once_and_only_once(): void
    {
        $this->signedIn();
        $this->post(route('account.password'), $this->passwordForm())->assertSessionHas('status', 'Your password was changed. Please sign in again.');
        $this->get('/login')->assertSee('Your password was changed.');
        $this->get('/login')->assertDontSee('Your password was changed.');
    }

    /** @return array<string, array{0: array<string, string>, 1: string, 2: string}> */
    public static function badPasswords(): array
    {
        $good = 'Sentinel-Pass-9f3a77c1!';
        $current = 'correct horse battery';

        return [
            'empty' => [['current_password' => $current, 'password' => '', 'password_confirmation' => ''], 'password', 'Enter a new password.'],
            '11 characters' => [['current_password' => $current, 'password' => 'abcdefghijk', 'password_confirmation' => 'abcdefghijk'], 'password', 'The new password must be at least 12 characters.'],
            'mismatch' => [['current_password' => $current, 'password' => $good, 'password_confirmation' => $good.'x'], 'password_confirmation', 'The password confirmation does not match.'],
            'confirmation missing' => [['current_password' => $current, 'password' => $good], 'password_confirmation', 'Confirm your new password.'],
            'same as current' => [['current_password' => $current, 'password' => $current, 'password_confirmation' => $current], 'password', 'Choose a new password that is different from the current one.'],
            'over 255' => [['current_password' => $current, 'password' => str_repeat('a', 256), 'password_confirmation' => str_repeat('a', 256)], 'password', 'The new password must not be longer than 255 characters.'],
            'over 72 bytes (bcrypt would truncate)' => [['current_password' => $current, 'password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], 'password', 'The new password must not be longer than 72 characters.'],
            'wrong current' => [['current_password' => 'nope nope nope', 'password' => $good, 'password_confirmation' => $good], 'current_password', 'The current password is incorrect.'],
        ];
    }

    /**
     * @dataProvider badPasswords
     *
     * @param  array<string, string>  $payload
     */
    #[DataProvider('badPasswords')]
    public function test_bad_passwords_are_rejected_and_nothing_changes(array $payload, string $field, string $message): void
    {
        $user = $this->signedIn();
        $hash = $user->password;

        $this->post(route('account.password'), $payload)
            ->assertRedirect('/roster#account')
            ->assertSessionHasErrors([$field => $message], null, 'accountPassword');

        $this->assertSame($hash, $user->fresh()->password);
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_twelve_characters_is_the_boundary_and_it_reuses_the_command_setting(): void
    {
        $this->assertSame(12, config('classpulse.password_min_length'));
        $this->assertSame(72, UpdatePasswordRequest::BCRYPT_MAX_BYTES);
        $user = $this->signedIn();

        $this->post(route('account.password'), $this->passwordForm('abcdefghijk'))->assertSessionHasErrors('password', null, 'accountPassword');
        $this->post(route('account.password'), $this->passwordForm('abcdefghijkl'))->assertRedirect('/login');
        $this->assertTrue(Hash::check('abcdefghijkl', $user->fresh()->password));
    }

    // ---- secrets never leak --------------------------------------------------------------------------------------

    public function test_passwords_never_appear_in_html_session_flash_logs_or_the_database_in_plaintext(): void
    {
        $captured = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$captured): void {
            $captured[] = $e->message.json_encode($e->context);
        });
        $logFile = storage_path('logs/laravel.log');
        $logBefore = is_file($logFile) ? (int) filesize($logFile) : 0;

        $user = $this->signedIn();
        $wrong = 'Sentinel-Wrong-1b2c3d4e!';
        $page = [];

        // Failed attempts: a wrong current password, a short new password, a mismatch. Nothing secret is flashed.
        $this->post(route('account.email'), $this->emailForm('bad', $wrong));
        $this->assertSame(['new_email'], array_keys((array) session('_old_input')));
        $this->assertStringNotContainsString('Sentinel', json_encode(session()->all()));
        $page[] = $this->get('/roster')->getContent();
        $this->post(route('account.password'), $this->passwordForm('Sentinel-Short', 'Sentinel-Other', $wrong));
        $this->assertSame([], array_intersect(['current_password', 'password', 'password_confirmation'], array_keys((array) session('_old_input'))));
        $this->assertStringNotContainsString('Sentinel', json_encode(session()->all()));
        $page[] = $this->get('/roster')->getContent();

        // The success path.
        $this->post(route('account.password'), $this->passwordForm());
        $page[] = $this->get('/login')->getContent();

        foreach ($page as $html) {
            $this->assertStringNotContainsString('Sentinel', $html);
        }
        $this->assertStringNotContainsString('Sentinel', json_encode(session()->all()));
        $this->assertStringNotContainsString('Sentinel', implode("\n", $captured));
        if (is_file($logFile)) {
            $added = (string) file_get_contents($logFile, false, null, $logBefore);
            $this->assertStringNotContainsString('Sentinel', $added);
        }

        $stored = $user->fresh()->password;
        $this->assertStringStartsWith('$2y$', $stored);
        $this->assertNotSame(self::NEW_PASSWORD, $stored);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $stored);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $stored));
        $this->assertSame(0, DB::table('users')->where('password', self::NEW_PASSWORD)->count());
        $this->assertStringNotContainsString('Sentinel', json_encode(DB::table('users')->get()->map(fn ($u) => [$u->email, $u->name, $u->remember_token])));
    }

    public function test_the_stored_hash_uses_the_configured_bcrypt_rounds(): void
    {
        $user = $this->signedIn();
        $this->post(route('account.password'), $this->passwordForm());

        $info = password_get_info($user->fresh()->password);
        $this->assertSame('bcrypt', $info['algoName']);
        $this->assertSame((int) config('hashing.bcrypt.rounds', env('BCRYPT_ROUNDS', 12)), $info['options']['cost']);
    }

    // ---- throttling ----------------------------------------------------------------------------------------------

    public function test_five_failures_then_429_on_both_endpoints_and_success_resets_the_counter(): void
    {
        RateLimiter::clear('account-credentials|1|127.0.0.1');
        $user = $this->signedIn();
        $max = (int) config('classpulse.account_max_attempts');
        $this->assertSame(5, $max);

        for ($i = 0; $i < $max; $i++) {
            $this->post(route('account.password'), $this->passwordForm(current: 'wrong wrong wrong'))->assertRedirect('/roster#account');
        }
        $blocked = $this->post(route('account.email'), $this->emailForm('x@example.test'));
        $blocked->assertStatus(429);
        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->assertStringContainsString('Too many failed attempts. Wait', $blocked->getContent());
        // Even the CORRECT password is refused while locked, and nothing changed.
        $this->post(route('account.password'), $this->passwordForm())->assertStatus(429);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        $this->assertSame('teacher@classpulse.test', $user->fresh()->email);
    }

    public function test_a_successful_change_clears_the_failed_attempts(): void
    {
        $this->signedIn();
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('account.email'), $this->emailForm('x@example.test', 'wrong wrong wrong'));
        }
        $this->post(route('account.email'), $this->emailForm('ok@example.test'))->assertSessionHas('account_email_changed');
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('account.email'), $this->emailForm('y@example.test', 'wrong wrong wrong'))->assertRedirect('/roster#account');
        }
        $this->post(route('account.email'), $this->emailForm('z@example.test'))->assertSessionHas('account_email_changed');
    }

    public function test_the_limiter_is_keyed_by_user_and_ip(): void
    {
        $a = $this->signedIn();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('account.email'), $this->emailForm('x@example.test', 'wrong wrong wrong'));
        }
        $this->post(route('account.email'), $this->emailForm('x@example.test'))->assertStatus(429);

        $b = $this->teacher('b@example.test');
        $this->actingAs($b)->post(route('account.email'), $this->emailForm('b2@example.test'))->assertSessionHas('account_email_changed');
        $this->assertNotSame($a->id, $b->id);
    }

    // ---- markup --------------------------------------------------------------------------------------------------

    public function test_the_avatar_menu_has_the_account_settings_link_before_log_out(): void
    {
        $this->signedIn();
        foreach (['/daily', '/weekly', '/semester', '/roster'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $menu = substr($html, (int) strpos($html, 'id="account-menu"'), 1800);
            $this->assertStringContainsString('<a class="account-item" role="menuitem" href="'.url('/roster').'#account" data-account-link>', $menu, $path);
            $this->assertStringContainsString('href="#icon-user"', $menu);
            $this->assertStringContainsString('Account settings', $menu);
            $this->assertLessThan(strpos($menu, 'Log out'), strpos($menu, 'Account settings'));
        }
    }

    public function test_the_account_group_renders_with_and_without_classes(): void
    {
        $this->signedIn();
        $empty = $this->get('/roster')->assertOk()->getContent();
        $this->assertStringContainsString('Create your first class', $empty);
        $this->assertStringContainsString('id="account"', $empty);
        $this->assertStringContainsString('teacher@classpulse.test', $empty);
        $this->assertStringContainsString('js/account.js', $empty);

        SchoolClass::factory()->create(['name' => 'HNL 2O']);
        $html = $this->get('/roster')->assertOk()->getContent();
        $this->assertStringContainsString('<details class="ro-group ro-group-solo" id="account" data-ro-group data-account-group>', $html);
        $this->assertSame(1, substr_count($html, 'id="account"'));
        $this->assertStringContainsString('Signed in as', $html);
        $this->assertStringContainsString('action="'.route('account.email').'"', $html);
        $this->assertStringContainsString('action="'.route('account.password').'"', $html);
        $this->assertStringNotContainsString('data-account-flash', $html);
    }

    public function test_the_group_opens_on_errors_and_after_an_email_change(): void
    {
        $this->signedIn();
        SchoolClass::factory()->create();
        $this->post(route('account.email'), $this->emailForm('nope'));
        $errors = $this->get('/roster')->getContent();
        $this->assertStringContainsString('id="account" data-ro-group data-account-group open>', $errors);
        $this->assertStringContainsString('1 to fix', $errors);
        $this->assertStringContainsString('aria-invalid="true" aria-describedby="account-new-email-error"', $errors);
        $this->assertStringContainsString('<p class="ro-error" id="account-new-email-error" role="alert">Enter a valid email address.</p>', $errors);

        $this->post(route('account.email'), $this->emailForm('fresh@example.test'));
        $this->assertStringContainsString('id="account" data-ro-group data-account-group open>', $this->get('/roster')->getContent());

        $this->post(route('account.password'), $this->passwordForm('short', 'short'));
        $pw = $this->get('/roster')->getContent();
        $this->assertStringContainsString('open>', substr($pw, (int) strpos($pw, 'id="account"'), 120));
        $this->assertStringContainsString('The new password must be at least 12 characters.', $pw);
        $this->assertStringContainsString('id="account-new-password-error" role="alert"', $pw);
    }

    public function test_every_password_field_has_an_accessible_toggle_and_the_right_autocomplete_and_no_value(): void
    {
        $this->signedIn();
        $html = $this->get('/roster')->getContent();
        $expected = [
            'account-email-password' => 'current-password',
            'account-current-password' => 'current-password',
            'account-new-password' => 'new-password',
            'account-confirm-password' => 'new-password',
        ];
        foreach ($expected as $id => $autocomplete) {
            $this->assertSame(1, preg_match('/<input[^>]*id="'.$id.'"[^>]*>/', $html, $input), $id);
            $this->assertStringContainsString('type="password"', $input[0]);
            $this->assertStringContainsString('value=""', $input[0]);
            $this->assertStringContainsString('autocomplete="'.$autocomplete.'"', $input[0]);
            $this->assertStringContainsString('required', $input[0]);
            $this->assertSame(1, preg_match('/<button type="button" class="pw-toggle" data-pw-toggle aria-pressed="false" aria-controls="'.$id.'" aria-label="Show password">/', $html), "toggle for $id");
            $this->assertStringContainsString('<label class="ui-eyebrow" for="'.$id.'">', $html);
        }
        $this->assertSame(4, substr_count($html, 'data-pw-toggle'));
        $this->assertSame(4, substr_count($html, 'href="#icon-eye"'));
        $this->assertSame(4, substr_count($html, 'href="#icon-eye-off"'));
        $this->assertStringContainsString('Use at least 12 characters.', $html);
        $this->assertStringContainsString('aria-describedby="account-new-password-hint"', $html);
        $this->assertSame(1, preg_match('/<input[^>]*id="account-new-email"[^>]*>/', $html, $email));
        $this->assertStringContainsString('type="email"', $email[0]);
        $this->assertStringContainsString('name="new_email"', $email[0]);
        $this->assertStringContainsString('<button type="submit" class="ui-btn ui-btn-primary"><svg', $html);
        $this->assertStringContainsString('Change email</button>', $html);
        $this->assertStringContainsString('Change password</button>', $html);
    }

    public function test_failed_forms_do_not_echo_passwords_back_into_the_html(): void
    {
        $this->signedIn();
        $this->post(route('account.password'), $this->passwordForm('Sentinel-Short', 'Sentinel-Other', 'Sentinel-Wrong-1b2c3d4e!'));
        $html = $this->get('/roster')->getContent();

        $this->assertStringNotContainsString('Sentinel', $html);
        $this->assertSame(4, preg_match_all('/<input[^>]*type="password"[^>]*value=""/', $html));
    }

    public function test_the_login_page_status_is_absent_without_a_flash(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertStringNotContainsString('login-status', $html);
    }

    public function test_the_account_group_has_no_inline_script_or_style_and_the_icon_exists(): void
    {
        $this->signedIn();
        $html = $this->get('/roster')->getContent();
        $group = substr($html, (int) strpos($html, 'id="account"'), 9000);
        $this->assertStringNotContainsString('style=', $group);
        $this->assertStringNotContainsString('onclick', $group);
        $this->assertStringContainsString('<symbol id="icon-eye-off"', $html);
        $this->assertStringContainsString('<symbol id="icon-user"', $html);
    }
}
