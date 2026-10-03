<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeacherCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    public function test_create_teacher_creates_exactly_one_user(): void
    {
        $this->artisan('classpulse:create-teacher', ['email' => 'teacher@classpulse.test', '--name' => 'Teacher'])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsOutput('Teacher account created for teacher@classpulse.test.')
            ->assertExitCode(0);

        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check(self::PASSWORD, User::first()->password));
    }

    public function test_create_teacher_refuses_when_a_user_exists(): void
    {
        User::factory()->create();

        $this->artisan('classpulse:create-teacher', ['email' => 'other@classpulse.test'])
            ->expectsOutput('A teacher account already exists.')
            ->assertExitCode(1);

        $this->assertSame(1, User::count());
    }

    public function test_create_teacher_rejects_short_password(): void
    {
        $this->artisan('classpulse:create-teacher', ['email' => 'teacher@classpulse.test'])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_create_teacher_rejects_mismatched_confirmation(): void
    {
        $this->artisan('classpulse:create-teacher', ['email' => 'teacher@classpulse.test'])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD.'x')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_reset_password_updates_hash_and_clears_sessions(): void
    {
        $user = User::factory()->create(['email' => 'teacher@classpulse.test']);
        DB::table('sessions')->insert([
            'id' => 'abc',
            'user_id' => $user->id,
            'payload' => 'x',
            'last_activity' => time(),
        ]);

        $this->artisan('classpulse:reset-password', ['email' => 'teacher@classpulse.test'])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsOutput('Password updated. All sessions were signed out.')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_reset_password_unknown_email_fails(): void
    {
        $this->artisan('classpulse:reset-password', ['email' => 'nobody@classpulse.test'])
            ->expectsOutput('No account with that email.')
            ->assertExitCode(1);
    }

    public function test_hash_prints_a_bcrypt_hash_without_the_password(): void
    {
        $this->artisan('classpulse:hash')
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsOutputToContain('$2y$')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertExitCode(0);
    }
}
