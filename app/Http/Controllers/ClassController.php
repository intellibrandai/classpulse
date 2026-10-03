<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyClassRequest;
use App\Http\Requests\StoreClassRequest;
use App\Http\Requests\UpdateClassRequest;
use App\Models\AcademicPeriod;
use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Services\ReportData;
use App\Support\AcademicPeriods;
use App\Support\CurrentClass;
use App\Support\ParticipationStats;
use App\Support\SchoolCalendar;
use App\Support\StudentName;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClassController extends Controller
{
    public function index(Request $request, ReportData $reports, ParticipationStats $stats, SchoolCalendar $calendar): View
    {
        $class = CurrentClass::resolve($request->integer('class') ?: null);

        $data = [
            'classes' => SchoolClass::query()->orderBy('name')->get(),
            'currentClass' => $class,
            'accountEmail' => (string) $request->user()->email,
            'classCards' => SchoolClass::query()->orderBy('name')
                ->withCount(['students as active_students_count' => fn ($q) => $q->whereNull('archived_at')])->get(),
        ];

        if ($class !== null) {
            $students = $class->students()->get();
            $active = $students->whereNull('archived_at')->sortBy(fn ($s) => StudentName::lastNameKey($s->display_name))->values();
            $rosterStats = $reports->roster($class);
            $data['activeStudents'] = $active;
            $data['rosterStats'] = $rosterStats;
            $data['archivedStudents'] = $students->whereNotNull('archived_at')->sortByDesc('archived_at')->values();
            $data['rosterRows'] = $active->map(function ($student) use ($rosterStats, $stats): array {
                $row = $rosterStats[$student->id] ?? ['average' => null, 'today' => 'none', 'today_points' => null];

                return [
                    'student' => $student,
                    'initials' => StudentName::initials($student->display_name),
                    'average' => $row['average'],
                    'average_text' => $stats->formatAverage($row['average']),
                    'today' => $row['today'],
                    'today_points' => $row['today_points'],
                ];
            })->all();
            $data['periods'] = $reports->periods($class);
            $monday = $calendar->weekStart($calendar->defaultDate());
            $data['exports'] = [
                'import' => url('/classes/'.$class->id.'/import'),
                'weekly' => ['label' => 'Weekly CSV, week of '.CarbonImmutable::createFromFormat('!Y-m-d', $monday, 'UTC')->format('M j, Y'), 'url' => url('/export/weekly.csv?class='.$class->id.'&week='.$monday)],
                'semester' => collect($data['periods'])->map(fn (array $period): array => [
                    'label' => 'Semester CSV, '.$period['label'],
                    'url' => url('/export/semester.csv?class='.$class->id.($period['key'] === AcademicPeriods::FULL ? '' : '&period='.$period['key'])),
                ])->values()->all(),
            ];
            $stored = $class->academicPeriods()->get()->keyBy('kind');
            $data['periodForm'] = collect(AcademicPeriod::KINDS)->mapWithKeys(fn (string $kind) => [$kind => [
                'label' => $stored->get($kind)?->label,
                'start' => $stored->get($kind)?->starts_on->format('Y-m-d'),
                'end' => $stored->get($kind)?->ends_on->format('Y-m-d'),
                'default_label' => AcademicPeriod::DEFAULT_LABELS[$kind],
            ]])->all();
            $data['studentCount'] = $students->count();
            $data['entryCount'] = ParticipationEntry::where('school_class_id', $class->id)->count();
        }

        return view('roster.index', $data);
    }

    public function store(StoreClassRequest $request): RedirectResponse
    {
        $class = SchoolClass::create($request->validated());

        return redirect(url('/roster?class='.$class->id))->with('status', 'Created '.$class->name.'.');
    }

    public function update(UpdateClassRequest $request, SchoolClass $class): RedirectResponse
    {
        DB::transaction(function () use ($request, $class): void {
            $class->update($request->classAttributes());
            if ($request->hasPeriodInput()) {
                $this->syncPeriods($request, $class);
            }
        });

        return redirect()->back()->with('status', 'Saved class details for '.$class->name.'.');
    }

    public function destroy(DestroyClassRequest $request, SchoolClass $class): RedirectResponse
    {
        $class->delete();

        return redirect(url('/roster'));
    }

    /**
     * Q1/Q2: both dates present saves the period (blank label falls back to the default); both blank removes it.
     */
    private function syncPeriods(UpdateClassRequest $request, SchoolClass $class): void
    {
        foreach (AcademicPeriod::KINDS as $kind) {
            $start = $request->input($kind.'_start');
            $end = $request->input($kind.'_end');
            if ($start === null || $end === null) {
                $class->academicPeriods()->where('kind', $kind)->delete();

                continue;
            }
            $class->academicPeriods()->updateOrCreate(['kind' => $kind], [
                'label' => $request->input($kind.'_label') ?? AcademicPeriod::DEFAULT_LABELS[$kind],
                'starts_on' => $start,
                'ends_on' => $end,
            ]);
        }
    }
}
