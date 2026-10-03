<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    private function teacher(): User
    {
        return User::factory()->create([
            'email' => 'teacher@classpulse.test',
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    public function test_guest_is_redirected_from_root_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_login_page_renders_form_and_csp(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Sign in');
        $response->assertSee('Student Participation Tracker');
        $response->assertSee('type="submit"', false);
        $response->assertSee('name="email"', false);
        $response->assertSee('name="password"', false);
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_login_page_has_no_invented_features(): void
    {
        $response = $this->get('/login');

        foreach (['Latency', 'FERPA', 'SSO', 'Clever', 'ClassLink', 'Remember this device', 'Forgot password', 'Continue to Gradebook'] as $fake) {
            $response->assertDontSee($fake);
        }
        $response->assertSee('name="email"', false);
        $response->assertSee('name="password"', false);
    }

    public function test_login_page_uses_the_product_description_and_no_single_teacher_wording(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('Class participation, one tap at a time.', $html);
        $this->assertStringContainsString('Track student participation and attendance, organize your classes, and review progress across the week and semester.', $html);
        $this->assertStringContainsString('<meta name="description" content="Track student participation and attendance, organize your classes, and review progress across the week and semester.">', $html);
        foreach (['one teacher', 'single teacher', '1 teacher', 'single-user', 'single user', 'una sola', 'un solo docente', 'único docente', 'solo una docente'] as $phrase) {
            $this->assertStringNotContainsStringIgnoringCase($phrase, $html, 'Forbidden phrase on the login page: '.$phrase);
        }
    }

    public function test_remember_email_checkbox_is_unchecked_unnamed_and_autofill_friendly(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<input[^>]*id="remember-email"[^>]*>/', $html, $box));
        $this->assertStringContainsString('type="checkbox"', $box[0]);
        $this->assertStringNotContainsString('checked', $box[0]);
        $this->assertStringNotContainsString('name=', $box[0], 'The box must never be posted to the server.');
        $this->assertStringContainsString('<label class="check-row" for="remember-email">', $html);
        $this->assertStringContainsString('Remember my email', $html);
        $this->assertStringContainsString('type="email" value="" autocomplete="username"', $html);
        $this->assertStringContainsString('type="password" autocomplete="current-password"', $html);
        // Only the hidden CSRF token carries autocomplete="off"; no visible field opts out of password managers.
        $this->assertStringNotContainsString('autocomplete="off"', preg_replace('/<input type="hidden"[^>]*>/', '', $html));
        $this->assertStringContainsString('js/login-remember.js', $html);
        $this->assertStringNotContainsString('Remember this device', $html);
        // The password field comes before the checkbox, the checkbox before the submit button.
        $this->assertLessThan(strpos($html, 'id="remember-email"'), strpos($html, 'id="password"'));
        $this->assertLessThan(strpos($html, 'Sign in</button>'), strpos($html, 'id="remember-email"'));
    }

    public function test_failed_login_keeps_the_typed_email_in_the_field(): void
    {
        $this->teacher();

        $this->from('/login')->post('/login', ['email' => 'teacher@classpulse.test', 'password' => 'wrong password 123']);
        $this->followingRedirects()->get('/login')->assertSee('value="teacher@classpulse.test"', false);
    }

    public function test_login_works_with_and_without_a_remember_field_and_never_sets_a_remember_cookie(): void
    {
        $user = $this->teacher();
        $lifetime = config('session.lifetime');

        $plain = $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $plain->assertRedirect('/daily');
        $this->assertAuthenticatedAs($user);
        $plainCookie = $plain->getCookie(config('session.cookie'));

        $this->post('/logout');
        $this->assertGuest();

        $with = $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD, 'remember' => '1', 'remember-email' => 'on']);
        $with->assertRedirect('/daily');
        $this->assertAuthenticatedAs($user);
        foreach ($with->headers->getCookies() as $cookie) {
            $this->assertStringStartsNotWith('remember_web_', $cookie->getName());
        }
        $this->assertSame($lifetime, config('session.lifetime'));
        $withCookie = $with->getCookie(config('session.cookie'));
        $this->assertEqualsWithDelta($plainCookie->getExpiresTime(), $withCookie->getExpiresTime(), 120, 'Session cookie lifetime is identical with and without the field.');
        $this->assertEqualsWithDelta(time() + $lifetime * 60, $withCookie->getExpiresTime(), 120, 'Session lifetime is the configured one, not extended.');
    }

    public function test_valid_credentials_authenticate_and_redirect_to_daily(): void
    {
        $user = $this->teacher();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect('/daily');

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_password_is_rejected_with_message(): void
    {
        $this->teacher();

        $this->from('/login')
            ->post('/login', ['email' => 'teacher@classpulse.test', 'password' => 'wrong password 123'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

        $this->assertGuest();
    }

    public function test_sixth_attempt_is_throttled(): void
    {
        $this->teacher();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'teacher@classpulse.test', 'password' => 'wrong password 123'])
                ->assertRedirect();
        }

        $response = $this->post('/login', ['email' => 'teacher@classpulse.test', 'password' => 'wrong password 123']);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
        $response->assertSee('Too many sign-in attempts. Try again in');
    }

    public function test_logout_ends_session_and_redirects_to_login(): void
    {
        $user = $this->teacher();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_authenticated_responses_are_no_store(): void
    {
        $response = $this->actingAs($this->teacher())->get('/');

        $response->assertRedirect('/daily');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_registration_and_password_reset_routes_do_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
        $this->get('/reset-password/abc')->assertNotFound();
    }
}
