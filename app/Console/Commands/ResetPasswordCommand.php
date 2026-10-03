<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ResetPasswordCommand extends Command
{
    protected $signature = 'classpulse:reset-password {email}';

    protected $description = 'Reset the teacher password and sign out every session';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No account with that email.');

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

        $user->password = Hash::make($password);
        $user->save();
        DB::table('sessions')->delete();

        $this->info('Password updated. All sessions were signed out.');

        return self::SUCCESS;
    }
}
