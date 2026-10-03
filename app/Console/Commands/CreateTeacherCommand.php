<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateTeacherCommand extends Command
{
    protected $signature = 'classpulse:create-teacher {email} {--name=Teacher}';

    protected $description = 'Create the teacher account';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('A teacher account already exists.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        if (strlen($password) < (int) config('classpulse.password_min_length')) {
            $this->error('The password must be at least '.config('classpulse.password_min_length').' characters.');

            return self::FAILURE;
        }

        if ($password !== $confirmation) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');

        User::create([
            'name' => (string) $this->option('name'),
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("Teacher account created for {$email}.");

        return self::SUCCESS;
    }
}
