<?php

namespace App\Http\Controllers;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\ReportData;
use App\Support\CsvWriter;
use App\Support\CurrentClass;
use App\Support\ParticipationStats;
use App\Support\SchoolCalendar;
use App\Support\WeeklyView;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WeeklyController extends Controller
{
    public function index(Request $request, SchoolCalendar $calendar, ReportData $reports): View|RedirectResponse
    {
        $class = CurrentClass::resolve($request->integer('class') ?: null);
        $classes = SchoolClass::query()->orderBy('name')->get();

        if ($class === null) {
            return view('reports.weekly', ['classes' => $classes, 'currentClass' => null]);
        }

        $week = $this->weekParam($request, $calendar);
        $monday = $calendar->weekStart($week);
        if ($monday !== $week) {
            return redirect(url('/weekly?class='.$class->id.'&week='.$monday));
        }

        $data = $reports->weekly($class, $monday);
        $view = WeeklyView::build($data, $calendar->today(), $class->name);
        $mondayDate = CarbonImmutable::createFromFormat('!Y-m-d', $monday, 'UTC');
        $thisMonday = $calendar->weekStart($calendar->defaultDate());

        return view('reports.weekly', [
            'classes' => $classes,
            'currentClass' => $class,
            'week' => $view,
            'students' => $data['students'],
            'weekNumber' => $this->weekNumber($class, $mondayDate, $calendar),
            'isCurrentWeek' => $monday === $thisMonday,
            'previousUrl' => url('/weekly?class='.$class->id.'&week='.$mondayDate->subDays(7)->format('Y-m-d')),
            'nextUrl' => url('/weekly?class='.$class->id.'&week='.$mondayDate->addDays(7)->format('Y-m-d')),
            'thisWeekUrl' => url('/weekly?class='.$class->id),
            'exportUrl' => url('/export/weekly.csv?class='.$class->id.'&week='.$monday),
        ]);
    }

    /**
     * Week n of the semester when the class has semester dates and the week falls inside them, otherwise the ISO week number.
     *
     * @return array{number: int, basis: string}
     */
    private function weekNumber(SchoolClass $class, CarbonImmutable $monday, SchoolCalendar $calendar): array
    {
        $start = $class->semester_start?->format('Y-m-d');
        $end = $class->semester_end?->format('Y-m-d');
        if ($start !== null && $monday->format('Y-m-d') >= $calendar->weekStart($start) && ($end === null || $monday->format('Y-m-d') <= $end)) {
            $first = CarbonImmutable::createFromFormat('!Y-m-d', $calendar->weekStart($start), 'UTC');

            return ['number' => intdiv((int) $first->diffInDays($monday), 7) + 1, 'basis' => 'semester'];
        }

        return ['number' => $monday->isoWeek(), 'basis' => 'iso'];
    }

    public function export(Request $request, SchoolCalendar $calendar, ParticipationStats $stats): Response|RedirectResponse
    {
        $class = CurrentClass::resolve($request->integer('class') ?: null);
        if ($class === null) {
            return redirect(url('/roster'));
        }

        $monday = $calendar->weekStart($this->weekParam($request, $calendar));
        $days = $calendar->weekDays($monday);
        $rows = $this->rows($class, $days, $stats);

        $body = CsvWriter::line(array_merge(
            ['Student', 'Student number'],
            $days,
            ['Total points', 'Present days recorded', 'Absences', 'Average per present day'],
        ));
        foreach ($rows['students'] as $row) {
            $body .= CsvWriter::line(array_merge(
                [$row['name'], $row['student_number']],
                array_map(fn (array $cell) => $cell['csv'], $row['cells']),
                [$row['total_points'], $row['present_days_recorded'], $row['absences'], $row['average']],
            ));
        }

        $filename = CsvWriter::filename('weekly', $class->name, $monday, $calendar->today());

        return new Response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function weekParam(Request $request, SchoolCalendar $calendar): string
    {
        $week = $request->query('week');
        if (is_string($week)) {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $week, 'UTC');
            if ($parsed !== false && $parsed->format('Y-m-d') === $week) {
                return $week;
            }
        }

        return $calendar->weekStart($calendar->defaultDate());
    }

    /**
     * @param  array<int, string>  $days
     * @return array{students: array<int, array<string, mixed>>, class: array<string, mixed>, entryCount: int}
     */
    private function rows(SchoolClass $class, array $days, ParticipationStats $stats): array
    {
        $entries = ParticipationEntry::query()->toBase()
            ->where('school_class_id', $class->id)
            ->whereBetween('work_date', [$days[0], $days[4]])
            ->get(['student_id', 'work_date', 'status', 'points'])
            ->map(fn ($e) => [
                'student_id' => (int) $e->student_id,
                'work_date' => (string) $e->work_date,
                'status' => (string) $e->status,
                'points' => $e->points === null ? null : (int) $e->points,
            ])->all();

        $withEntries = array_unique(array_column($entries, 'student_id'));
        $students = Student::query()
            ->where('school_class_id', $class->id)
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', $withEntries))
            ->get()
            ->sort(fn ($a, $b) => strcmp(mb_strtolower($a->display_name), mb_strtolower($b->display_name)) ?: $a->id <=> $b->id);

        $byStudent = [];
        foreach ($entries as $entry) {
            $byStudent[$entry['student_id']][$entry['work_date']] = $entry;
        }

        $out = [];
        foreach ($students as $student) {
            $own = $byStudent[$student->id] ?? [];
            $summary = $stats->summarize(array_values($own), $days[0], $days[4]);
            $cells = [];
            foreach ($days as $day) {
                $cells[] = $this->cell($own[$day] ?? null);
            }
            $out[] = [
                'id' => $student->id,
                'name' => $student->display_name.($student->isArchived() ? ' (archived)' : ''),
                'student_number' => $student->student_number ?? '',
                'cells' => $cells,
                'total_points' => $summary['total_points'],
                'present_days_recorded' => $summary['present_days_recorded'],
                'absences' => $summary['absences'],
                'average' => $stats->formatAverage($summary['average']),
            ];
        }

        return [
            'students' => $out,
            'class' => $stats->summarize($entries, $days[0], $days[4]),
            'entryCount' => count($entries),
        ];
    }

    /**
     * @param  array{status: string, points: ?int}|null  $entry
     * @return array{text: string, heat: string, csv: int|string|null}
     */
    private function cell(?array $entry): array
    {
        if ($entry === null) {
            return ['text' => '—', 'heat' => '', 'csv' => null];
        }
        if ($entry['status'] === 'absent') {
            return ['text' => 'A', 'heat' => 'heat-absent', 'csv' => 'A'];
        }
        $points = (int) $entry['points'];
        $heat = match (true) {
            $points >= 6 => 'heat-high',
            $points >= 3 => 'heat-mid',
            $points >= 1 => 'heat-low',
            default => 'heat-0',
        };

        return ['text' => (string) $points, 'heat' => $heat, 'csv' => $points];
    }
}
