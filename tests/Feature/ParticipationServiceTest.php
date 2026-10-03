<?php

namespace Tests\Feature;

use App\Exceptions\ParticipationException;
use App\Models\ParticipationEntry;
use App\Models\ParticipationEvent;
use App\Models\ParticipationOperation;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\ParticipationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParticipationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-21';

    private SchoolClass $class;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 10:00', 'America/Toronto'));
        $this->class = SchoolClass::factory()->create();
        $this->student = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Alex Rivera']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function apply(string $kind, ?int $studentId = null, string $date = self::DATE): array
    {
        return app(ParticipationService::class)->apply($this->class, $date, (string) Str::uuid(), $kind, $studentId ?? $this->student->id);
    }

    private function assertRejected(string $code, int $status, callable $callback): void
    {
        try {
            $callback();
        } catch (ParticipationException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->status);

            return;
        }
        $this->fail("Expected ParticipationException {$code}");
    }

    private function entry(?Student $student = null): ?ParticipationEntry
    {
        return ParticipationEntry::where('student_id', ($student ?? $this->student)->id)->where('work_date', self::DATE)->first();
    }

    public function test_increment_creates_present_one_and_counts_up(): void
    {
        $state = $this->apply('increment');
        $this->assertSame(['student_id' => $this->student->id, 'status' => 'present', 'points' => 1, 'revision' => 1], $state['entries'][0]);
        $this->assertFalse($state['replayed']);

        $state = $this->apply('increment');
        $this->assertSame(2, $state['entries'][0]['points']);
        $this->assertSame(2, $state['entries'][0]['revision']);
        $this->assertSame(2, $state['summary']['total_points']);
        $this->assertSame(1, $state['summary']['present_recorded']);
        $this->assertSame(0, $state['summary']['not_recorded']);
    }

    public function test_increment_stops_at_99_and_blocks_absent_students(): void
    {
        ParticipationEntry::factory()->create(['school_class_id' => $this->class->id, 'student_id' => $this->student->id, 'work_date' => self::DATE, 'points' => 99]);
        $this->assertRejected('points_max', 422, fn () => $this->apply('increment'));
        $this->assertSame(99, $this->entry()->points);

        $this->apply('absent_on');
        $this->assertRejected('student_absent', 409, fn () => $this->apply('increment'));
        $this->assertRejected('student_absent', 409, fn () => $this->apply('decrement'));
    }

    public function test_decrement_rules(): void
    {
        $this->assertRejected('not_recorded', 422, fn () => $this->apply('decrement'));

        $this->apply('increment');
        $this->apply('increment');
        $state = $this->apply('decrement');
        $this->assertSame(1, $state['entries'][0]['points']);
        $state = $this->apply('decrement');
        $this->assertSame(0, $state['entries'][0]['points']);
        $this->assertSame('present', $state['entries'][0]['status']);
        $this->assertRejected('points_min', 422, fn () => $this->apply('decrement'));
    }

    public function test_points_never_go_below_zero_in_the_database(): void
    {
        $this->apply('set_zero');
        $this->assertRejected('points_min', 422, fn () => $this->apply('decrement'));
        $this->assertRejected('points_min', 422, fn () => $this->apply('decrement'));

        $entry = $this->entry();
        $this->assertSame(0, (int) $entry->points);
        $this->assertSame('present', $entry->status);
    }

    public function test_set_zero_only_on_a_day_without_a_row(): void
    {
        $state = $this->apply('set_zero');
        $this->assertSame(0, $state['entries'][0]['points']);
        $this->assertSame('present', $state['entries'][0]['status']);
        $this->assertRejected('already_recorded', 409, fn () => $this->apply('set_zero'));

        $this->apply('absent_on');
        $this->assertRejected('already_recorded', 409, fn () => $this->apply('set_zero'));
    }

    public function test_absent_off_restores_remembered_points(): void
    {
        $this->apply('increment');
        $this->apply('increment');
        $this->apply('increment');

        $state = $this->apply('absent_on');
        $this->assertSame('absent', $state['entries'][0]['status']);
        $this->assertNull($state['entries'][0]['points']);
        $this->assertSame(3, $this->entry()->restore_points);
        $this->assertSame(0, $state['summary']['total_points']);
        $this->assertSame(1, $state['summary']['absent']);
        $this->assertRejected('already_absent', 409, fn () => $this->apply('absent_on'));

        $state = $this->apply('absent_off');
        $this->assertSame('present', $state['entries'][0]['status']);
        $this->assertSame(3, $state['entries'][0]['points']);
        $this->assertNull($this->entry()->restore_points);
    }

    public function test_absent_off_after_absent_with_nothing_recorded_deletes_the_row(): void
    {
        $this->assertRejected('not_absent', 409, fn () => $this->apply('absent_off'));

        $this->apply('absent_on');
        $state = $this->apply('absent_off');

        $this->assertSame('none', $state['entries'][0]['status']);
        $this->assertNull($this->entry());

        $this->apply('set_zero');
        $this->assertRejected('not_absent', 409, fn () => $this->apply('absent_off'));
    }

    public function test_every_operation_records_an_event_with_the_before_snapshot(): void
    {
        $this->apply('increment');
        $this->apply('absent_on');

        $events = ParticipationEvent::orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertFalse((bool) $events[0]->before_exists);
        $this->assertTrue((bool) $events[1]->before_exists);
        $this->assertSame('present', $events[1]->before_status);
        $this->assertSame(1, $events[1]->before_points);
    }

    public function test_rejected_operations_write_nothing(): void
    {
        $this->assertRejected('not_recorded', 422, fn () => $this->apply('decrement'));

        $this->assertSame(0, ParticipationOperation::count());
        $this->assertSame(0, ParticipationEvent::count());
        $this->assertSame(0, ParticipationEntry::count());
    }

    public function test_student_of_another_class_archived_student_weekend_and_future_are_rejected(): void
    {
        $other = Student::factory()->create();
        $archived = Student::factory()->archived()->create(['school_class_id' => $this->class->id]);

        $this->assertRejected('not_found', 404, fn () => $this->apply('increment', $other->id));
        $this->assertRejected('not_found', 404, fn () => $this->apply('increment', 999999));
        $this->assertRejected('student_archived', 422, fn () => $this->apply('increment', $archived->id));
        $this->assertRejected('weekend', 422, fn () => $this->apply('increment', null, '2026-10-24'));
        $this->assertRejected('weekend', 422, fn () => $this->apply('increment', null, '2026-10-25'));
        $this->assertRejected('future_date', 422, fn () => $this->apply('increment', null, '2026-10-22'));

        $this->assertSame(0, ParticipationOperation::count());
    }

    public function test_day_state_lists_active_students_in_roster_order(): void
    {
        Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'alex adams']);
        Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Zed Young']);
        Student::factory()->archived()->create(['school_class_id' => $this->class->id, 'display_name' => 'Aaron Gone']);

        $state = app(ParticipationService::class)->dayState($this->class, self::DATE);

        $this->assertSame(3, $state['summary']['active_students']);
        $this->assertSame(3, $state['summary']['not_recorded']);
        $this->assertSame(0, $state['day_version']);
        $this->assertFalse($state['can_undo']);
        $names = array_map(fn (array $e) => Student::find($e['student_id'])->display_name, $state['entries']);
        $this->assertSame(['alex adams', 'Alex Rivera', 'Zed Young'], $names);
    }

    public function test_day_version_and_can_undo_follow_operations(): void
    {
        $first = $this->apply('increment');
        $second = $this->apply('increment');

        $this->assertGreaterThan($first['day_version'], $second['day_version']);
        $this->assertTrue($second['can_undo']);
    }
}
