<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnvironmentGuardTest extends TestCase
{
    public function test_suite_uses_the_test_database_and_toronto_time(): void
    {
        $this->assertSame('mariadb', config('database.default'));
        $this->assertSame('classpulse_test', DB::connection()->getDatabaseName());
        $this->assertSame('America/Toronto', config('app.timezone'));
        $this->assertSame(35, config('classpulse.max_roster'));
        $this->assertSame(99, config('classpulse.max_points'));
    }
}
