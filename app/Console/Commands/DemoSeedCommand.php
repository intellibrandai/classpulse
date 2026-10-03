<?php

namespace App\Console\Commands;

use App\Exceptions\ParticipationException;
use App\Models\AcademicPeriod;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use App\Services\ParticipationService;
use App\Support\SchoolCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DemoSeedCommand extends Command
{
    protected $signature = 'classpulse:demo-seed {--days=20 : Number of previous school days to fill} {--periods : Also configure Q1 and Q2 dates}';

    protected $description = 'Create fictitious demo classes, students and participation (local only)';

    private const FIRST_NAMES = ['Alex', 'Jordan', 'Robin', 'Sam', 'Taylor', 'Morgan', 'Casey', 'Riley', 'Avery', 'Quinn', 'Jamie', 'Skyler', 'Dana', 'Reese', 'Rowan', 'Emery', 'Kai', 'Noel', 'Sage', 'Devon'];

    private const LAST_NAMES = ['Rivera', 'Lee', 'Sky', 'Nguyen', 'Patel', 'Okafor', 'Silva', 'Tremblay', 'Kowalski', 'Haddad', 'Moreau', 'Singh', 'Larsen', 'Chen', 'Duarte', 'Ferrari', 'Bennett', 'Ito', 'Cohen', 'Novak'];

    public function handle(ParticipationService $service, SchoolCalendar $calendar): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('Demo seed only runs when APP_ENV=local.');

            return self::FAILURE;
        }
        if (SchoolClass::query()->exists()) {
            $this->error('Classes already exist; the demo seed only runs on an empty database.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $dates = [];
        $date = $calendar->defaultDate();
        for ($i = 0; $i < $days; $i++) {
            $date = $calendar->previousWeekday($date);
            $dates[] = $date;
        }
        $dates = array_reverse($dates);

        $catalog = [
            'HNL 2O' => ['Food and Nutrition', 'Nutrition & Health', 'Period 2 (10:15 - 11:35)', 'Room 114'],
            'HNC 3C' => ['Food and Culture', 'Food & Culture', 'Period 3 (12:20 - 13:40)', 'Lab 2'],
            'HLS 3O' => ['Living Skills', 'Living Skills', 'Period 4 (13:45 - 15:05)', 'Room 118'],
        ];
        foreach ($catalog as $name => [$subject, $title, $schedule, $room]) {
            $class = SchoolClass::create([
                'name' => $name,
                'title' => $title,
                'subject_description' => $subject,
                'period_label' => null,
                'room' => $room,
                'schedule' => $schedule,
                'roster_cap' => config('classpulse.max_roster'),
            ]);
            $students = $this->createStudents($class);
            foreach ($dates as $day) {
                $this->fillDay($service, $class, $students, $day);
            }
            $this->addProfiles($class, $students, $dates);
            if ($this->option('periods')) {
                $this->addPeriods($class, $dates);
            }
            $this->info("Seeded {$name}: {$students->count()} students, ".count($dates).' days.');
        }

        if (! User::query()->exists()) {
            $password = Str::password(20);
            User::create(['name' => 'Teacher', 'email' => 'teacher@classpulse.test', 'password' => $password]);
            $this->line('Teacher: teacher@classpulse.test');
            $this->line("Password: {$password}");
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Student>
     */
    private function createStudents(SchoolClass $class)
    {
        $names = [];
        foreach (self::FIRST_NAMES as $first) {
            foreach (self::LAST_NAMES as $last) {
                $names[] = "{$first} {$last}";
            }
        }
        shuffle($names);

        return collect(array_slice($names, 0, random_int(12, 18)))
            ->map(fn (string $name) => Student::create(['school_class_id' => $class->id, 'display_name' => $name]));
    }

    /**
     * @param  Collection<int, Student>  $students
     */
    private function fillDay(ParticipationService $service, SchoolClass $class, $students, string $date): void
    {
        foreach ($students->values() as $index => $student) {
            if ($index > 0 && random_int(1, 100) <= 8) {
                $service->apply($class, $date, (string) Str::uuid(), 'absent_on', $student->id);

                continue;
            }
            $count = $index === 0 ? random_int(1, 4) : random_int(0, 5);
            for ($i = 0; $i < $count; $i++) {
                $service->apply($class, $date, (string) Str::uuid(), 'increment', $student->id);
            }
        }

        try {
            $service->apply($class, $date, (string) Str::uuid(), 'zero_remaining');
        } catch (ParticipationException $e) {
            if ($e->errorCode !== 'nothing_to_do') {
                throw $e;
            }
        }
    }

    /**
     * Preferred names, one roster observation and a few dated notes (all fictitious).
     *
     * @param  Collection<int, Student>  $students
     * @param  array<int, string>  $dates
     */
    private function addProfiles(SchoolClass $class, $students, array $dates): void
    {
        foreach ($students->values()->take(3) as $index => $student) {
            $student->update([
                'preferred_name' => explode(' ', $student->display_name)[0].'-'.($index + 1),
                'observations' => $index === 0 ? 'Prefers working in pairs. Seating near the front.' : null,
            ]);
        }
        $first = $students->first();
        if ($first !== null) {
            StudentNote::create([
                'school_class_id' => $class->id,
                'student_id' => $first->id,
                'note_date' => $dates[count($dates) - 1],
                'body' => 'Led the group discussion and summarised the recipe steps clearly.',
            ]);
        }
    }

    /**
     * Splits the seeded dates into Q1 (first half) and Q2 (second half).
     *
     * @param  array<int, string>  $dates
     */
    private function addPeriods(SchoolClass $class, array $dates): void
    {
        $half = intdiv(count($dates), 2);
        if ($half < 1) {
            return;
        }
        $ranges = ['q1' => [$dates[0], $dates[$half - 1]], 'q2' => [$dates[$half], $dates[count($dates) - 1]]];
        foreach ($ranges as $kind => [$start, $end]) {
            AcademicPeriod::create([
                'school_class_id' => $class->id,
                'kind' => $kind,
                'label' => AcademicPeriod::DEFAULT_LABELS[$kind],
                'starts_on' => $start,
                'ends_on' => $end,
            ]);
        }
    }
}
