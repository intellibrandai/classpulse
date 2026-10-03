<?php

namespace App\Services;

use App\Exceptions\ParticipationException;
use App\Models\ParticipationEntry;
use App\Models\ParticipationEvent;
use App\Models\ParticipationOperation;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\SchoolCalendar;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of participation entries, operations and events.
 */
class ParticipationService
{
    private const STUDENT_KINDS = ['increment', 'decrement', 'set_zero', 'set_points', 'clear', 'absent_on', 'absent_off'];

    public function __construct(private readonly SchoolCalendar $calendar) {}

    /**
     * @return array<string, mixed> the day state
     */
    public function apply(SchoolClass $class, string $date, string $opId, string $kind, ?int $studentId = null, ?int $points = null): array
    {
        if (! $this->calendar->isWeekday($date)) {
            throw ParticipationException::for('weekend');
        }
        if ($this->calendar->isFuture($date)) {
            throw ParticipationException::for('future_date');
        }

        return DB::transaction(function () use ($class, $date, $opId, $kind, $studentId, $points) {
            SchoolClass::whereKey($class->id)->lockForUpdate()->first();

            if (ParticipationOperation::where('op_id', $opId)->exists()) {
                return $this->dayState($class, $date, true);
            }

            if (in_array($kind, self::STUDENT_KINDS, true)) {
                $this->applyToStudent($class, $date, $opId, $kind, $studentId, $points);
            } elseif ($kind === 'zero_remaining') {
                $this->zeroRemaining($class, $date, $opId);
            } elseif ($kind === 'reset_day') {
                $this->resetDay($class, $date, $opId);
            } elseif ($kind === 'undo') {
                $this->undo($class, $date, $opId);
            } else {
                throw new ParticipationException('validation', 422, 'Unknown operation.');
            }

            return $this->dayState($class, $date);
        });
    }

    /**
     * @return array<string, mixed> the day state
     */
    public function dayState(SchoolClass $class, string $date, bool $replayed = false): array
    {
        $students = $class->activeStudents()->get()
            ->sort(fn (Student $a, Student $b) => strcmp(mb_strtolower($a->display_name), mb_strtolower($b->display_name)) ?: $a->id <=> $b->id)
            ->values();

        $entries = ParticipationEntry::where('school_class_id', $class->id)
            ->where('work_date', $date)
            ->get()
            ->keyBy('student_id');

        $items = [];
        $presentRecorded = 0;
        $absent = 0;
        $totalPoints = 0;

        foreach ($students as $student) {
            $entry = $entries->get($student->id);
            if ($entry === null) {
                $items[] = ['student_id' => $student->id, 'status' => 'none', 'points' => null, 'revision' => 0];

                continue;
            }
            if ($entry->status === 'absent') {
                $absent++;
                $items[] = ['student_id' => $student->id, 'status' => 'absent', 'points' => null, 'revision' => (int) $entry->revision];

                continue;
            }
            $presentRecorded++;
            $totalPoints += (int) $entry->points;
            $items[] = ['student_id' => $student->id, 'status' => 'present', 'points' => (int) $entry->points, 'revision' => (int) $entry->revision];
        }

        $operations = ParticipationOperation::where('school_class_id', $class->id)->where('work_date', $date);
        $recorded = $presentRecorded + $absent;

        return [
            'day_version' => (int) (clone $operations)->max('seq'),
            'entries' => $items,
            'summary' => [
                'active_students' => $students->count(),
                'recorded' => $recorded,
                'present_recorded' => $presentRecorded,
                'absent' => $absent,
                'not_recorded' => $students->count() - $recorded,
                'total_points' => $totalPoints,
            ],
            'can_undo' => (clone $operations)->where('kind', '!=', 'undo')->whereNull('undone_at')->exists(),
            'replayed' => $replayed,
        ];
    }

    private function applyToStudent(SchoolClass $class, string $date, string $opId, string $kind, ?int $studentId, ?int $points = null): void
    {
        $student = $studentId === null
            ? null
            : Student::where('school_class_id', $class->id)->whereKey($studentId)->first();
        if ($student === null) {
            throw ParticipationException::for($studentId === null ? 'validation' : 'not_found');
        }
        if ($student->isArchived()) {
            throw ParticipationException::for('student_archived');
        }

        $entry = ParticipationEntry::where('school_class_id', $class->id)
            ->where('student_id', $student->id)
            ->where('work_date', $date)
            ->lockForUpdate()
            ->first();

        // Plan the transition first so a business error leaves nothing written.
        $plan = $this->plan($kind, $entry, $points);

        $operation = $this->recordOperation($class, $date, $opId, $kind);
        $this->recordEvent($operation, $class, $student->id, $entry);

        match ($plan['action']) {
            'create' => ParticipationEntry::create([
                'school_class_id' => $class->id,
                'student_id' => $student->id,
                'work_date' => $date,
                'revision' => 1,
            ] + $plan['values']),
            'update' => $entry->update($plan['values'] + ['revision' => $entry->revision + 1]),
            'delete' => $entry->delete(),
        };
    }

    private function zeroRemaining(SchoolClass $class, string $date, string $opId): void
    {
        $recorded = ParticipationEntry::where('school_class_id', $class->id)
            ->where('work_date', $date)
            ->lockForUpdate()
            ->pluck('student_id');

        $studentIds = $class->activeStudents()->whereNotIn('id', $recorded)->orderBy('id')->pluck('id');
        if ($studentIds->isEmpty()) {
            throw ParticipationException::for('nothing_to_do');
        }

        $operation = $this->recordOperation($class, $date, $opId, 'zero_remaining');
        foreach ($studentIds as $studentId) {
            $this->recordEvent($operation, $class, $studentId, null);
            ParticipationEntry::create([
                'school_class_id' => $class->id,
                'student_id' => $studentId,
                'work_date' => $date,
                'status' => 'present',
                'points' => 0,
                'restore_points' => null,
                'revision' => 1,
            ]);
        }
    }

    private function resetDay(SchoolClass $class, string $date, string $opId): void
    {
        $entries = ParticipationEntry::where('school_class_id', $class->id)
            ->where('work_date', $date)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($entries->isEmpty()) {
            throw ParticipationException::for('nothing_to_do');
        }

        $operation = $this->recordOperation($class, $date, $opId, 'reset_day');
        foreach ($entries as $entry) {
            $this->recordEvent($operation, $class, $entry->student_id, $entry);
        }
        ParticipationEntry::whereIn('id', $entries->pluck('id'))->delete();
    }

    private function undo(SchoolClass $class, string $date, string $opId): void
    {
        $target = ParticipationOperation::where('school_class_id', $class->id)
            ->where('work_date', $date)
            ->where('kind', '!=', 'undo')
            ->whereNull('undone_at')
            ->orderByDesc('seq')
            ->first();
        if ($target === null) {
            throw ParticipationException::for('nothing_to_undo');
        }

        $events = ParticipationEvent::where('operation_seq', $target->seq)->orderByDesc('id')->get();
        foreach ($events as $event) {
            $entry = ParticipationEntry::where('school_class_id', $class->id)
                ->where('student_id', $event->student_id)
                ->where('work_date', $date)
                ->lockForUpdate()
                ->first();

            if (! $event->before_exists) {
                $entry?->delete();

                continue;
            }

            $values = [
                'status' => $event->before_status,
                'points' => $event->before_points,
                'restore_points' => $event->before_restore_points,
            ];
            if ($entry === null) {
                ParticipationEntry::create($values + [
                    'school_class_id' => $class->id,
                    'student_id' => $event->student_id,
                    'work_date' => $date,
                    'revision' => 1,
                ]);
            } else {
                $entry->update($values + ['revision' => $entry->revision + 1]);
            }
        }

        $target->update(['undone_at' => now()]);
        $this->recordOperation($class, $date, $opId, 'undo');
    }

    /**
     * @return array{action: string, values: array<string, mixed>}
     */
    private function plan(string $kind, ?ParticipationEntry $entry, ?int $points = null): array
    {
        $absent = $entry?->status === 'absent';
        $present = $entry !== null && ! $absent;

        return match ($kind) {
            'increment' => match (true) {
                $entry === null => $this->create('present', 1),
                $absent => throw ParticipationException::for('student_absent'),
                $entry->points >= config('classpulse.max_points') => throw ParticipationException::for('points_max'),
                default => $this->update('present', $entry->points + 1),
            },
            'decrement' => match (true) {
                $entry === null => throw ParticipationException::for('not_recorded'),
                $absent => throw ParticipationException::for('student_absent'),
                $entry->points <= 0 => throw ParticipationException::for('points_min'),
                default => $this->update('present', $entry->points - 1),
            },
            'set_zero' => $entry === null
                ? $this->create('present', 0)
                : throw ParticipationException::for('already_recorded'),
            // Absolute value from the Weekly quick editor: NR -> P(n), P(m) -> P(n); an absent student must be marked present first.
            'set_points' => match (true) {
                $points === null => throw ParticipationException::for('validation'),
                $points < 0 => throw ParticipationException::for('points_min'),
                $points > config('classpulse.max_points') => throw ParticipationException::for('points_max'),
                $absent => throw ParticipationException::for('student_absent'),
                $entry === null => $this->create('present', $points),
                $entry->points === $points => throw ParticipationException::for('unchanged'),
                default => $this->update('present', $points),
            },
            // Back to Not recorded: removes the row (present or absent); undo restores it from the event.
            'clear' => $entry === null
                ? throw ParticipationException::for('not_recorded')
                : ['action' => 'delete', 'values' => []],
            'absent_on' => match (true) {
                $entry === null => $this->create('absent', null, null),
                $present => $this->update('absent', null, $entry->points),
                default => throw ParticipationException::for('already_absent'),
            },
            'absent_off' => match (true) {
                ! $absent => throw ParticipationException::for('not_absent'),
                $entry->restore_points === null => ['action' => 'delete', 'values' => []],
                default => $this->update('present', $entry->restore_points, null),
            },
        };
    }

    /**
     * @return array{action: string, values: array<string, mixed>}
     */
    private function create(string $status, ?int $points, ?int $restore = null): array
    {
        return ['action' => 'create', 'values' => ['status' => $status, 'points' => $points, 'restore_points' => $restore]];
    }

    /**
     * @return array{action: string, values: array<string, mixed>}
     */
    private function update(string $status, ?int $points, ?int $restore = null): array
    {
        return ['action' => 'update', 'values' => ['status' => $status, 'points' => $points, 'restore_points' => $restore]];
    }

    private function recordOperation(SchoolClass $class, string $date, string $opId, string $kind): ParticipationOperation
    {
        return ParticipationOperation::create([
            'op_id' => $opId,
            'school_class_id' => $class->id,
            'work_date' => $date,
            'kind' => $kind,
        ]);
    }

    private function recordEvent(ParticipationOperation $operation, SchoolClass $class, int $studentId, ?ParticipationEntry $before): void
    {
        ParticipationEvent::create([
            'operation_seq' => $operation->seq,
            'school_class_id' => $class->id,
            'student_id' => $studentId,
            'before_exists' => $before !== null,
            'before_status' => $before?->status,
            'before_points' => $before?->points,
            'before_restore_points' => $before?->restore_points,
        ]);
    }
}
