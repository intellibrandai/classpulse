<?php

namespace App\Http\Controllers;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\ReportData;
use App\Support\AcademicPeriods;
use App\Support\CsvWriter;
use App\Support\CurrentClass;
use App\Support\ParticipationStats;
use App\Support\SchoolCalendar;
use App\Support\SemesterView;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SemesterController extends Controller
{
    public function index(Request $request, SchoolCalendar $calendar, ReportData $reports): View
    {
        $class = CurrentClass::resolve($request->integer('class') ?: null);
        $classes = SchoolClass::query()->orderBy('name')->get();

        if ($class === null) {
            return view('reports.semester', ['classes' => $classes, 'currentClass' => null]);
        }

        $key = $request->query('period');
        $data = $reports->semester($class, is_string($key) ? $key : null);
        $semester = SemesterView::build($data, $calendar->today());
        $periodQuery = $semester['period']['key'] === AcademicPeriods::FULL ? '' : '&period='.$semester['period']['key'];

        return view('reports.semester', [
            'classes' => $classes,
            'currentClass' => $class,
            'semester' => $semester,
            'pageUrl' => url('/semester?class='.$class->id),
            'exportUrl' => url('/export/semester.csv?class='.$class->id.$periodQuery),
            'settingsUrl' => url('/roster?class='.$class->id.'#periods'),
            'studentApi' => url('/api/classes/'.$class->id.'/students/{student}/semester'),
            'noteApi' => url('/api/classes/'.$class->id.'/students/{student}/notes'),
            'commentsApi' => url('/api/classes/'.$class->id.'/report-comments'),
            'commentApi' => url('/api/classes/'.$class->id.'/students/{student}/report-comments/'.$semester['period']['key']),
            'today' => $calendar->today(),
        ]);
    }

    public function export(Request $request, SchoolCalendar $calendar, ParticipationStats $stats): Response|RedirectResponse
    {
        $class = CurrentClass::resolve($request->integer('class') ?: null);
        if ($class === null) {
            return redirect(url('/roster'));
        }

        $period = self::period($class, $calendar);
        $key = $request->query('period');
        if (is_string($key) && in_array($key, ['q1', 'q2'], true)) {
            $quarter = $class->academicPeriods()->where('kind', $key)->first();
            if ($quarter !== null) {
                $to = min($quarter->ends_on->format('Y-m-d'), $calendar->today());
                $from = $quarter->starts_on->format('Y-m-d');
                $period = $from > $to ? ['from' => null, 'to' => null] : ['from' => $from, 'to' => $to];
            }
        }
        $data = $this->build($class, $period, $stats);

        $body = CsvWriter::line([
            'Student', 'Student number', 'Total points', 'Present days recorded', 'Absences',
            'Days recorded', 'Participation days', 'Average per present day',
        ]);
        foreach ($data['rows'] as $row) {
            $body .= CsvWriter::line([
                $row['name'], $row['student_number'], $row['total_points'], $row['present_days_recorded'],
                $row['absences'], $row['days_recorded'], $row['participation_days'], $row['average'],
            ]);
        }

        $today = $calendar->today();
        $range = ($period['from'] ?? $today).'-to-'.($period['to'] ?? $today);
        $filename = CsvWriter::filename('semester', $class->name, $range, $today);

        return new Response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Semester period of a class: from semester_start (or its earliest entry) to min(semester_end, today).
     *
     * @return array{from: ?string, to: ?string}
     */
    public static function period(SchoolClass $class, SchoolCalendar $calendar): array
    {
        $from = $class->semester_start?->format('Y-m-d');
        if ($from === null) {
            $earliest = ParticipationEntry::query()->toBase()->where('school_class_id', $class->id)->min('work_date');
            $from = $earliest === null ? null : (string) $earliest;
        }
        if ($from === null) {
            return ['from' => null, 'to' => null];
        }

        $to = $calendar->today();
        $end = $class->semester_end?->format('Y-m-d');
        if ($end !== null && $end < $to) {
            $to = $end;
        }

        return $from > $to ? ['from' => null, 'to' => null] : ['from' => $from, 'to' => $to];
    }

    /**
     * @param  array{from: ?string, to: ?string}  $period
     * @return array{rows: array<int, array<string, mixed>>, class: array<string, mixed>, entryCount: int}
     */
    private function build(SchoolClass $class, array $period, ParticipationStats $stats): array
    {
        $entries = [];
        if ($period['from'] !== null) {
            $entries = ParticipationEntry::query()->toBase()
                ->where('school_class_id', $class->id)
                ->whereBetween('work_date', [$period['from'], $period['to']])
                ->get(['student_id', 'work_date', 'status', 'points'])
                ->map(fn ($e) => [
                    'student_id' => (int) $e->student_id,
                    'work_date' => (string) $e->work_date,
                    'status' => (string) $e->status,
                    'points' => $e->points === null ? null : (int) $e->points,
                ])->all();
        }

        $byStudent = [];
        foreach ($entries as $entry) {
            $byStudent[$entry['student_id']][] = $entry;
        }

        $students = Student::query()
            ->where('school_class_id', $class->id)
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', array_keys($byStudent)))
            ->get()
            ->sort(fn ($a, $b) => strcmp(mb_strtolower($a->display_name), mb_strtolower($b->display_name)) ?: $a->id <=> $b->id);

        $rows = [];
        foreach ($students as $student) {
            $summary = $stats->summarize($byStudent[$student->id] ?? []);
            $rows[] = [
                'id' => $student->id,
                'name' => $student->display_name.($student->isArchived() ? ' (archived)' : ''),
                'student_number' => $student->student_number ?? '',
                'total_points' => $summary['total_points'],
                'present_days_recorded' => $summary['present_days_recorded'],
                'absences' => $summary['absences'],
                'days_recorded' => $summary['days_recorded'],
                'participation_days' => $summary['participation_days'],
                'average' => $stats->formatAverage($summary['average']),
            ];
        }

        return ['rows' => $rows, 'class' => $stats->summarize($entries), 'entryCount' => count($entries)];
    }
}
