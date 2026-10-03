<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class HashPasswordCommand extends Command
{
    protected $signature = 'classpulse:hash';

    protected $description = 'Print a bcrypt hash of a password (to paste into phpMyAdmin)';

    public function handle(): int
    {
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

        $this->line(Hash::make($password));

        return self::SUCCESS;
    }
}
