<?php

namespace Tests\Feature;

use App\Models\ParticipationOperation;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\SchoolCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_refuses_outside_local(): void
    {
        $this->artisan('classpulse:demo-seed', ['--days' => 2])
            ->expectsOutput('Demo seed only runs when APP_ENV=local.')
            ->assertExitCode(1);

        $this->assertSame(0, SchoolClass::count());
        $this->assertSame(0, User::count());
    }

    public function test_seeds_three_classes_through_the_service_in_local(): void
    {
        $this->app['env'] = 'local';

        $this->artisan('classpulse:demo-seed', ['--days' => 2])->assertExitCode(0);

        $this->assertSame(3, SchoolClass::count());
        $this->assertSame(['HLS 3O', 'HNC 3C', 'HNL 2O'], SchoolClass::orderBy('name')->pluck('name')->all());
        foreach (SchoolClass::all() as $class) {
            $this->assertGreaterThanOrEqual(12, $class->students()->count());
            $this->assertLessThanOrEqual(18, $class->students()->count());

            $calendar = new SchoolCalendar(config('classpulse.school_timezone'));
            $date = $calendar->previousWeekday($calendar->defaultDate());
            for ($i = 0; $i < 2; $i++) {
                $this->assertGreaterThanOrEqual(
                    1,
                    ParticipationOperation::where('school_class_id', $class->id)->where('work_date', $date)->count(),
                    "{$class->name} has no operation on {$date}",
                );
                $date = $calendar->previousWeekday($date);
            }
        }
        $this->assertSame(1, User::where('email', 'teacher@classpulse.test')->count());
        $this->assertGreaterThan(0, Student::count());
    }

    public function test_prints_password_once_and_refuses_when_classes_exist(): void
    {
        $this->app['env'] = 'local';

        $this->artisan('classpulse:demo-seed', ['--days' => 1])
            ->expectsOutputToContain('Teacher: teacher@classpulse.test')
            ->expectsOutputToContain('Password: ')
            ->assertExitCode(0);

        $this->artisan('classpulse:demo-seed', ['--days' => 1])->assertExitCode(1);
        $this->assertSame(3, SchoolClass::count());
    }

    public function test_seed_fills_class_details_preferred_names_and_a_note(): void
    {
        $this->app['env'] = 'local';

        $this->artisan('classpulse:demo-seed', ['--days' => 4])->assertExitCode(0);

        foreach (SchoolClass::all() as $class) {
            $this->assertNotNull($class->title);
            $this->assertNotNull($class->room);
            $this->assertNotNull($class->schedule);
            $this->assertSame(3, $class->students()->whereNotNull('preferred_name')->count());
            $this->assertSame(1, $class->students()->whereNotNull('observations')->count());
            $this->assertSame(1, $class->notes()->count());
            $this->assertSame(0, $class->academicPeriods()->count());
        }
    }

    public function test_periods_option_configures_non_overlapping_quarters(): void
    {
        $this->app['env'] = 'local';

        $this->artisan('classpulse:demo-seed', ['--days' => 4, '--periods' => true])->assertExitCode(0);

        foreach (SchoolClass::all() as $class) {
            $q1 = $class->academicPeriods()->where('kind', 'q1')->sole();
            $q2 = $class->academicPeriods()->where('kind', 'q2')->sole();
            $this->assertLessThan($q2->starts_on->format('Y-m-d'), $q1->ends_on->format('Y-m-d'));
            $this->assertSame('Q1 / Midterm', $q1->label);
        }
    }
}
