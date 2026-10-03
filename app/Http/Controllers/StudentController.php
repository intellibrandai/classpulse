<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentRequest;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class StudentController extends Controller
{
    public function store(StudentRequest $request, SchoolClass $class): RedirectResponse
    {
        // The seat is re-counted inside the transaction, with the class row locked, so two requests cannot both take the last seat.
        $student = DB::transaction(function () use ($class, $request): ?Student {
            $locked = SchoolClass::query()->whereKey($class->id)->lockForUpdate()->firstOrFail();

            return $locked->remainingCapacity() > 0 ? $locked->students()->create($request->validated()) : null;
        });
        if ($student === null) {
            return $this->full($class);
        }

        // The Daily Tracker quick-add sends return_to=daily and the shown date; everything else goes to the roster.
        if ($request->input('return_to') === 'daily') {
            $date = (string) $request->input('date');
            $real = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));

            return redirect(url('/daily?class='.$class->id.($real ? '&date='.$date : '')))
                ->with('status', 'Added '.$student->display_name.' to '.$class->name.'.');
        }

        return $this->toRoster($class, 'Added '.$student->display_name.'.');
    }

    public function update(StudentRequest $request, Student $student): RedirectResponse
    {
        $student->update($request->validated());

        return $this->toRoster($student->schoolClass, 'Saved '.$student->display_name.'.');
    }

    public function archive(Student $student): RedirectResponse
    {
        if (! $student->isArchived()) {
            $student->update(['archived_at' => now()]);
        }

        return $this->toRoster($student->schoolClass, 'Archived '.$student->display_name.'. Their records are kept; restore them anytime.');
    }

    public function restore(Student $student): RedirectResponse
    {
        $class = $student->schoolClass;

        if ($student->isArchived()) {
            $restored = DB::transaction(function () use ($class, $student): bool {
                $locked = SchoolClass::query()->whereKey($class->id)->lockForUpdate()->firstOrFail();
                if ($locked->remainingCapacity() < 1) {
                    return false;
                }
                $student->update(['archived_at' => null]);

                return true;
            });
            if (! $restored) {
                return $this->full($class);
            }
        }

        return $this->toRoster($class, 'Restored '.$student->display_name.'.');
    }

    private function toRoster(SchoolClass $class, ?string $status = null): RedirectResponse
    {
        $redirect = redirect(url('/roster?class='.$class->id));

        return $status === null ? $redirect : $redirect->with('status', $status);
    }

    private function full(SchoolClass $class): RedirectResponse
    {
        return redirect()->back()->withInput()->withErrors(['roster_cap' => $class->fullMessage()]);
    }
}
