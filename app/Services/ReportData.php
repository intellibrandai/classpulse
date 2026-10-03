<?php

namespace App\Services;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentNote;
use App\Support\AcademicPeriods;
use App\Support\BarSeries;
use App\Support\SchoolCalendar;
use App\Support\SemesterStats;
use App\Support\Sparkline;
use App\Support\StudentRoster;
use App\Support\WeeklyStats;
use Carbon\CarbonImmutable;

/**
 * Read-only assembler: loads rows for a class and feeds the pure calculators. Every query is scoped by class.
 * Contracts are documented in docs/api-notes.md.
 */
class ReportData
{
    public function __construct(
        private readonly SchoolCalendar $calendar,
        private readonly WeeklyStats $weeklyStats,
        private readonly SemesterStats $semesterStats,
        private readonly StudentRoster $rosterStats,
    ) {}

    /**
     * @return array<int, array{student_id: int, work_date: string, status: string, points: ?int}>
     */
    public function rows(SchoolClass $class, string $from, string $to): array
    {
        return ParticipationEntry::query()->toBase()
            ->where('school_class_id', $class->id)
            ->whereBetween('work_date', [$from, $to])
            ->get(['student_id', 'work_date', 'status', 'points'])
            ->map(fn ($e) => [
                'student_id' => (int) $e->student_id,
                'work_date' => substr((string) $e->work_date, 0, 10),
                'status' => (string) $e->status,
                'points' => $e->points === null ? null : (int) $e->points,
            ])->all();
    }

    /**
     * Periods a class can show: Full Semester plus the configured Q1/Q2 (see AcademicPeriods::resolve).
     *
     * @return array<string, array{key: string, label: string, from: ?string, to: ?string, configured: bool}>
     */
    public function periods(SchoolClass $class): array
    {
        $configured = [];
        foreach ($class->academicPeriods()->get() as $p) {
            $configured[$p->kind] = ['label' => $p->label, 'starts_on' => $p->starts_on->format('Y-m-d'), 'ends_on' => $p->ends_on->format('Y-m-d')];
        }
        $range = ParticipationEntry::query()->toBase()->where('school_class_id', $class->id)
            ->selectRaw('MIN(work_date) AS first_date, MAX(work_date) AS last_date')->first();

        return AcademicPeriods::resolve(
            $class->semester_start?->format('Y-m-d'),
            $class->semester_end?->format('Y-m-d'),
            $configured,
            $range?->first_date === null ? null : substr((string) $range->first_date, 0, 10),
            $range?->last_date === null ? null : substr((string) $range->last_date, 0, 10),
        );
    }

    /**
     * One ISO week (Monday given) for the Weekly Matrix: WeeklyStats::compute() plus per-student rows and chart geometry.
     *
     * @return array{
     *     monday: string, days: array<int, string>, stats: array<string, mixed>,
     *     students: array<int, array{id: int, name: string, preferred_name: ?string, student_number: ?string, archived: bool, cells: array<int, array{status: string, points: ?int}>}>,
     *     charts: array{average: array<string, mixed>, points: array<string, mixed>}
     * }
     */
    public function weekly(SchoolClass $class, string $monday): array
    {
        $days = $this->calendar->weekDays($monday);
        $previous = CarbonImmutable::createFromFormat('!Y-m-d', $monday, 'UTC')->subDays(7)->format('Y-m-d');
        $rows = $this->rows($class, $previous, $days[4]);
        $weekRows = array_values(array_filter($rows, fn (array $r) => $r['work_date'] >= $days[0]));

        $active = Student::query()->where('school_class_id', $class->id)->whereNull('archived_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $stats = $this->weeklyStats->compute($days, $weekRows, $active, $rows, $this->calendar->today());

        $withEntries = array_unique(array_column($weekRows, 'student_id'));
        $students = Student::query()->where('school_class_id', $class->id)
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', $withEntries))
            ->get()
            ->sort(fn ($a, $b) => strcmp(mb_strtolower($a->display_name), mb_strtolower($b->display_name)) ?: $a->id <=> $b->id);

        $cellByKey = [];
        foreach ($weekRows as $r) {
            $cellByKey[$r['student_id']][$r['work_date']] = ['status' => $r['status'], 'points' => $r['status'] === 'present' ? (int) $r['points'] : null];
        }
        $out = [];
        foreach ($students as $s) {
            $out[] = [
                'id' => $s->id,
                'name' => $s->display_name,
                'preferred_name' => $s->preferred_name,
                'student_number' => $s->student_number,
                'archived' => $s->isArchived(),
                'cells' => array_map(fn (string $d) => $cellByKey[$s->id][$d] ?? ['status' => 'none', 'points' => null], $days),
            ];
        }

        return [
            'monday' => $monday,
            'days' => $days,
            'stats' => $stats,
            'students' => $out,
            'charts' => [
                'average' => Sparkline::build($stats['day_averages'], 120, 36),
                'points' => BarSeries::build($stats['day_points'], 120, 36),
            ],
        ];
    }

    /**
     * Semester Analytics for a period key ('full', 'q1', 'q2'; unknown falls back to full).
     *
     * @return array{
     *     periods: array<string, array<string, mixed>>, selected: array<string, mixed>, previous: ?array<string, mixed>,
     *     stats: array<string, mixed>,
     *     students: array<int, array{id: int, name: string, preferred_name: ?string, student_number: ?string, archived: bool}>,
     *     notes: array<int, array<int, array{date: string, body: string}>>
     * }
     */
    public function semester(SchoolClass $class, ?string $periodKey): array
    {
        $periods = $this->periods($class);
        $selected = AcademicPeriods::select($periods, $periodKey);
        $previous = AcademicPeriods::previous($periods, $selected);

        $bounds = array_filter([$selected['from'], $previous['from'] ?? null]);
        $ends = array_filter([$selected['to'], $previous['to'] ?? null]);
        $today = $this->calendar->today();
        $rows = $bounds === [] || $ends === [] ? [] : $this->rows($class, min($bounds), min(max($ends), $today));

        $withEntries = array_values(array_unique(array_column($rows, 'student_id')));
        $students = Student::query()->where('school_class_id', $class->id)
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', $withEntries))
            ->get()
            ->sort(fn ($a, $b) => strcmp(mb_strtolower($a->display_name), mb_strtolower($b->display_name)) ?: $a->id <=> $b->id)
            ->values();

        $stats = $this->semesterStats->compute(
            ['from' => $selected['from'], 'to' => $selected['to']],
            $rows,
            $students->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $today,
            $previous === null ? null : ['from' => $previous['from'], 'to' => $previous['to']],
        );

        $notes = [];
        foreach (StudentNote::query()->where('school_class_id', $class->id)->orderByDesc('note_date')->get() as $n) {
            $notes[$n->student_id][] = ['date' => $n->note_date->format('Y-m-d'), 'body' => $n->body];
        }

        return [
            'periods' => $periods,
            'selected' => $selected,
            'previous' => $previous,
            'stats' => $stats,
            'students' => $students->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->display_name,
                'preferred_name' => $s->preferred_name,
                'student_number' => $s->student_number,
                'archived' => $s->isArchived(),
            ])->all(),
            'notes' => $notes,
        ];
    }

    /**
     * One student's entry on a date (scoped by class): present with points, absent, or none (Not recorded).
     *
     * @return array{date: string, status: string, points: ?int}
     */
    public function studentDay(SchoolClass $class, Student $student, string $date): array
    {
        $entry = ParticipationEntry::query()->toBase()
            ->where('school_class_id', $class->id)
            ->where('student_id', $student->id)
            ->where('work_date', $date)
            ->first(['status', 'points']);

        if ($entry === null) {
            return ['date' => $date, 'status' => 'none', 'points' => null];
        }

        return [
            'date' => $date,
            'status' => (string) $entry->status,
            'points' => $entry->status === 'present' ? (int) $entry->points : null,
        ];
    }

    /**
     * Roster figures for the active students of a class (semester average per present day, today's status).
     *
     * @return array<int, array{student_id: int, average: ?float, present_days: int, absences: int, today: string, today_points: ?int}>
     */
    public function roster(SchoolClass $class): array
    {
        $periods = $this->periods($class);
        $full = $periods[AcademicPeriods::FULL];
        $schoolDate = $this->calendar->defaultDate();
        $from = $full['from'] ?? $schoolDate;
        $to = max($full['to'] ?? $schoolDate, $schoolDate);
        $rows = $this->rows($class, min($from, $schoolDate), $to);
        $ids = $class->activeStudents()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $this->rosterStats->build($ids, $rows, $schoolDate, $full['from'], $full['to']);
    }

    /**
     * Cadence series for one student's Daily side panel: weekly averages per present day over the Full Semester.
     *
     * @return array<int, array{monday: string, from: string, to: string, present_days: int, average: ?float}>
     */
    public function studentCadence(SchoolClass $class, Student $student): array
    {
        $full = $this->periods($class)[AcademicPeriods::FULL];
        if ($full['from'] === null) {
            return [];
        }
        $to = min($full['to'], $this->calendar->today());
        $rows = array_values(array_filter(
            $this->rows($class, $full['from'], $to),
            fn (array $r) => $r['student_id'] === $student->id,
        ));

        return $this->semesterStats->weeklyAverages($rows, $full['from'], $to);
    }

    /**
     * Cadence for many students with a single query: weekly averages over the Full Semester per student id.
     *
     * @param  array<int, int>  $studentIds
     * @return array<int, array<int, array{monday: string, from: string, to: string, present_days: int, average: ?float}>>
     */
    public function cadenceFor(SchoolClass $class, array $studentIds): array
    {
        $full = $this->periods($class)[AcademicPeriods::FULL];
        if ($full['from'] === null) {
            return array_fill_keys($studentIds, []);
        }
        $to = min($full['to'], $this->calendar->today());
        $byStudent = [];
        foreach ($this->rows($class, $full['from'], $to) as $row) {
            $byStudent[$row['student_id']][] = $row;
        }

        $out = [];
        foreach ($studentIds as $id) {
            $out[$id] = $this->semesterStats->weeklyAverages($byStudent[$id] ?? [], $full['from'], $to);
        }

        return $out;
    }
}
