<?php

namespace App\Http\Controllers;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\CsvWriter;
use App\Support\CurrentClass;
use App\Support\ParticipationStats;
use App\Support\SchoolCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

class StudentHistoryController extends Controller
{
    public function show(Student $student, SchoolCalendar $calendar, ParticipationStats $stats): View
    {
        $class = $student->schoolClass;
        CurrentClass::resolve($class->id);

        $period = SemesterController::period($class, $calendar);
        $rows = $this->rows($student, $class, $period);
        $summary = $stats->summarize($rows);

        $weeks = [];
        if ($period['from'] !== null) {
            foreach ($stats->weekly($rows, $period['from'], $period['to'], $calendar) as $monday => $bucket) {
                if ($bucket['days_recorded'] > 0) {
                    $weeks[] = [
                        'monday' => $monday,
                        'total_points' => $bucket['total_points'],
                        'present_days_recorded' => $bucket['present_days_recorded'],
                        'absences' => $bucket['absences'],
                        'average' => $stats->formatAverage($bucket['average']),
                    ];
                }
            }
        }

        return view('reports.student', [
            'classes' => SchoolClass::query()->orderBy('name')->get(),
            'currentClass' => $class,
            'student' => $student,
            'period' => $period,
            'summary' => $summary,
            'average' => $stats->formatAverage($summary['average']),
            'history' => array_map(fn (array $row) => [
                'date' => $row['work_date'],
                'weekday' => $this->weekday($row['work_date']),
                'status' => $row['status'] === 'present' ? 'Present' : 'Absent',
                'points' => $row['points'],
            ], $rows),
            'weeks' => $weeks,
            'exportUrl' => url('/export/student/'.$student->id.'.csv'),
        ]);
    }

    public function export(Student $student, SchoolCalendar $calendar): Response
    {
        $class = $student->schoolClass;
        $period = SemesterController::period($class, $calendar);

        $body = CsvWriter::line(['Date', 'Weekday', 'Status', 'Points']);
        foreach ($this->rows($student, $class, $period) as $row) {
            $body .= CsvWriter::line([
                $row['work_date'],
                $this->weekday($row['work_date']),
                $row['status'] === 'present' ? 'Present' : 'Absent',
                $row['status'] === 'present' ? (int) $row['points'] : null,
            ]);
        }

        $today = $calendar->today();
        $range = ($period['from'] ?? $today).'-to-'.($period['to'] ?? $today);
        $filename = CsvWriter::filename('student', $class->name.' '.$student->display_name, $range, $today);

        return new Response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  array{from: ?string, to: ?string}  $period
     * @return array<int, array{student_id: int, work_date: string, status: string, points: ?int}>
     */
    private function rows(Student $student, SchoolClass $class, array $period): array
    {
        if ($period['from'] === null) {
            return [];
        }

        return ParticipationEntry::query()->toBase()
            ->where('school_class_id', $class->id)
            ->where('student_id', $student->id)
            ->whereBetween('work_date', [$period['from'], $period['to']])
            ->orderBy('work_date')
            ->get(['student_id', 'work_date', 'status', 'points'])
            ->map(fn ($e) => [
                'student_id' => (int) $e->student_id,
                'work_date' => (string) $e->work_date,
                'status' => (string) $e->status,
                'points' => $e->points === null ? null : (int) $e->points,
            ])->all();
    }

    private function weekday(string $date): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->format('l');
    }
}
