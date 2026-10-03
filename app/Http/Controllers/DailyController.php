<?php

namespace App\Http\Controllers;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentNote;
use App\Services\ParticipationService;
use App\Services\ReportData;
use App\Support\CurrentClass;
use App\Support\ParticipationStats;
use App\Support\SchoolCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DailyController extends Controller
{
    public function __invoke(Request $request, ParticipationService $service, SchoolCalendar $calendar, ParticipationStats $stats, ReportData $reports): View|RedirectResponse
    {
        $class = CurrentClass::resolve($request->integer('class') ?: null);
        $classes = SchoolClass::query()->orderBy('name')->get();

        if ($class === null) {
            return view('daily.index', ['classes' => $classes, 'currentClass' => null]);
        }

        $date = $request->query('date');
        if (! is_string($date) || ! $this->isRealDate($date)) {
            $date = $calendar->defaultDate();
        }

        if (! $calendar->isWeekday($date)) {
            return $this->redirectTo($class, $calendar->previousWeekday($date));
        }
        if ($calendar->isFuture($date)) {
            return $this->redirectTo($class, $calendar->defaultDate());
        }

        $day = $service->dayState($class, $date);
        $students = Student::where('school_class_id', $class->id)
            ->whereIn('id', array_column($day['entries'], 'student_id'))
            ->get()
            ->keyBy('id');

        $cards = array_map(fn (array $entry) => ['student' => $students[$entry['student_id']], 'entry' => $entry], $day['entries']);

        $next = $calendar->nextWeekday($date);
        $studentIds = array_keys($students->all());
        $week = $this->weekBreakdown($class, $date, $studentIds, $calendar);
        $notes = $this->notesFor($class, $date, $studentIds);

        return view('daily.index', [
            'classes' => $classes,
            'currentClass' => $class,
            'date' => $date,
            'dateText' => CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->format('l, M j, Y'),
            'dateShort' => CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->format('D, M j'),
            'previousUrl' => url('/daily?class='.$class->id.'&date='.$calendar->previousWeekday($date)),
            'nextUrl' => $calendar->isFuture($next) ? null : url('/daily?class='.$class->id.'&date='.$next),
            'todayUrl' => url('/daily?class='.$class->id),
            'day' => $day,
            'cards' => $cards,
            'week' => $week,
            'counts' => $this->filterCounts($day['entries']),
            'todayNotes' => $notes['today'],
            'recentNotes' => $notes['recent'],
            'cadence' => $reports->cadenceFor($class, $studentIds),
            'activeCount' => $class->activeStudentCount(),
            'mean' => $stats->formatAverage($day['summary']['present_recorded'] === 0 ? null : $day['summary']['total_points'] / $day['summary']['present_recorded']),
            'entryCount' => ParticipationEntry::where('school_class_id', $class->id)->where('work_date', $date)->count(),
        ]);
    }

    /**
     * Current-week rows (Monday to Friday) per student, read from the database.
     *
     * @param  array<int, int>  $studentIds
     * @return array<int, array<int, array{date: string, label: string, selected: bool, today: bool, state: string, points: ?int}>>
     */
    private function weekBreakdown(SchoolClass $class, string $date, array $studentIds, SchoolCalendar $calendar): array
    {
        $days = $calendar->weekDays($calendar->weekStart($date));
        $entries = ParticipationEntry::where('school_class_id', $class->id)
            ->whereIn('student_id', $studentIds)
            ->whereBetween('work_date', [$days[0], $days[4]])
            ->get()
            ->groupBy('student_id');

        $week = [];
        foreach ($studentIds as $studentId) {
            $byDate = ($entries->get($studentId) ?? collect())->keyBy(fn (ParticipationEntry $e) => $e->work_date instanceof \DateTimeInterface ? $e->work_date->format('Y-m-d') : (string) $e->work_date);
            foreach ($days as $day) {
                $entry = $byDate->get($day);
                $state = match (true) {
                    $entry?->status === 'absent' => 'absent',
                    $entry?->status === 'present' => 'present',
                    $calendar->isFuture($day) => 'upcoming',
                    default => 'none',
                };
                $week[$studentId][] = [
                    'date' => $day,
                    'label' => CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')->format('D, M j'),
                    'selected' => $day === $date,
                    'today' => $day === $calendar->today(),
                    'state' => $state,
                    'points' => $state === 'present' ? (int) $entry->points : null,
                ];
            }
        }

        return $week;
    }

    /**
     * Counts behind the filter chips: Active = present with points > 0, Zero = present with 0 points.
     *
     * @param  array<int, array{status: string, points: ?int}>  $entries
     * @return array{all: int, active: int, zero: int, absent: int, none: int}
     */
    private function filterCounts(array $entries): array
    {
        $counts = ['all' => count($entries), 'active' => 0, 'zero' => 0, 'absent' => 0, 'none' => 0];
        foreach ($entries as $entry) {
            if ($entry['status'] === 'absent') {
                $counts['absent']++;
            } elseif ($entry['status'] === 'present') {
                $counts[(int) $entry['points'] > 0 ? 'active' : 'zero']++;
            } else {
                $counts['none']++;
            }
        }

        return $counts;
    }

    /**
     * Notes for the shown date (body per student) and each student's last three notes on other dates.
     *
     * @param  array<int, int>  $studentIds
     * @return array{today: array<int, string>, recent: array<int, array<int, array{date: string, label: string, excerpt: string}>>}
     */
    private function notesFor(SchoolClass $class, string $date, array $studentIds): array
    {
        $today = [];
        $recent = [];
        $rows = StudentNote::query()->toBase()
            ->where('school_class_id', $class->id)
            ->whereIn('student_id', $studentIds)
            ->orderByDesc('note_date')
            ->get(['student_id', 'note_date', 'body']);

        foreach ($rows as $row) {
            $noteDate = substr((string) $row->note_date, 0, 10);
            $studentId = (int) $row->student_id;
            if ($noteDate === $date) {
                $today[$studentId] = (string) $row->body;

                continue;
            }
            if (count($recent[$studentId] ?? []) < 3) {
                $recent[$studentId][] = [
                    'date' => $noteDate,
                    'label' => CarbonImmutable::createFromFormat('!Y-m-d', $noteDate, 'UTC')->format('M j'),
                    'excerpt' => Str::limit(trim(preg_replace('/\s+/', ' ', (string) $row->body) ?? ''), 90),
                ];
            }
        }

        return ['today' => $today, 'recent' => $recent];
    }

    private function redirectTo(SchoolClass $class, string $date): RedirectResponse
    {
        return redirect(url('/daily?class='.$class->id.'&date='.$date));
    }

    private function isRealDate(string $date): bool
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
