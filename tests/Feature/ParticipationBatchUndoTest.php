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

class ParticipationBatchUndoTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-21';

    private SchoolClass $class;

    /** @var array<string, Student> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 10:00', 'America/Toronto'));
        $this->class = SchoolClass::factory()->create();
        foreach (['a' => 'Alex Rivera', 'b' => 'Blair Ito', 'c' => 'Casey Lee'] as $key => $name) {
            $this->s[$key] = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function apply(string $kind, ?string $student = null): array
    {
        return app(ParticipationService::class)->apply($this->class, self::DATE, (string) Str::uuid(), $kind, $student ? $this->s[$student]->id : null);
    }

    private function rejected(string $code, int $status, callable $callback): void
    {
        try {
            $callback();
        } catch (ParticipationException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->status);

            return;
        }
        $this->fail("Expected {$code}");
    }

    /** @return array<int, array<string, mixed>> */
    private function snapshot(): array
    {
        return ParticipationEntry::orderBy('student_id')->get(['student_id', 'status', 'points', 'restore_points'])->toArray();
    }

    public function test_zero_remaining_only_touches_active_students_without_a_row(): void
    {
        $archived = Student::factory()->archived()->create(['school_class_id' => $this->class->id]);
        $this->apply('increment', 'a');
        $this->apply('absent_on', 'b');

        $state = $this->apply('zero_remaining');

        $this->assertSame(1 + 1 + 1, $state['summary']['recorded']);
        $this->assertSame(0, ParticipationEntry::where('student_id', $this->s['c']->id)->value('points'));
        $this->assertSame(1, ParticipationEntry::where('student_id', $this->s['a']->id)->value('points'));
        $this->assertSame('absent', ParticipationEntry::where('student_id', $this->s['b']->id)->value('status'));
        $this->assertSame(0, ParticipationEntry::where('student_id', $archived->id)->count());
        $this->rejected('nothing_to_do', 422, fn () => $this->apply('zero_remaining'));
    }

    public function test_reset_day_deletes_only_that_class_and_date(): void
    {
        $otherDate = ParticipationEntry::factory()->create(['school_class_id' => $this->class->id, 'student_id' => $this->s['a']->id, 'work_date' => '2026-10-20', 'points' => 4]);
        $otherClass = ParticipationEntry::factory()->create();
        $this->rejected('nothing_to_do', 422, fn () => $this->apply('reset_day'));

        $this->apply('increment', 'a');
        $this->apply('set_zero', 'b');
        $state = $this->apply('reset_day');

        $this->assertSame(0, $state['summary']['recorded']);
        $this->assertNotNull($otherDate->fresh());
        $this->assertNotNull($otherClass->fresh());
        $this->assertSame(2, ParticipationEntry::count());
    }

    public function test_undo_restores_reset_day_as_one_batch(): void
    {
        $this->apply('increment', 'a');
        $this->apply('increment', 'a');
        $this->apply('absent_on', 'b');
        $this->apply('set_zero', 'c');
        $before = $this->snapshot();

        $this->apply('reset_day');
        $this->assertSame(0, ParticipationEntry::count());

        $state = $this->apply('undo');

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(3, $state['summary']['recorded']);
        $this->assertNotNull(ParticipationOperation::where('kind', 'reset_day')->first()->undone_at);
        $this->assertSame(0, ParticipationEvent::where('operation_seq', ParticipationOperation::where('kind', 'undo')->value('seq'))->count());
    }

    public function test_undo_walks_back_one_operation_at_a_time_with_exact_state(): void
    {
        $this->apply('increment', 'a');
        $afterOne = $this->snapshot();
        $this->apply('increment', 'a');
        $this->apply('absent_on', 'a');
        $absentSnapshot = $this->snapshot();
        $this->assertSame(2, $absentSnapshot[0]['restore_points']);

        $this->apply('undo');
        $this->assertSame(2, ParticipationEntry::first()->points);
        $this->assertSame('present', ParticipationEntry::first()->status);
        $this->assertNull(ParticipationEntry::first()->restore_points);

        $this->apply('undo');
        $this->assertSame($afterOne, $this->snapshot());

        $state = $this->apply('undo');
        $this->assertSame(0, ParticipationEntry::count());
        $this->assertFalse($state['can_undo']);
        $this->rejected('nothing_to_undo', 409, fn () => $this->apply('undo'));
    }

    public function test_undo_of_zero_remaining_and_absent_on_restores_the_batch(): void
    {
        $this->apply('increment', 'a');
        $this->apply('zero_remaining');
        $this->assertSame(3, ParticipationEntry::count());
        $this->apply('undo');
        $this->assertSame(1, ParticipationEntry::count());

        $this->apply('absent_on', 'a');
        $this->apply('undo');
        $this->assertSame('present', ParticipationEntry::first()->status);
        $this->assertSame(1, ParticipationEntry::first()->points);
    }

    public function test_undo_is_replay_safe(): void
    {
        $this->apply('increment', 'a');
        $opId = (string) Str::uuid();
        $service = app(ParticipationService::class);

        $service->apply($this->class, self::DATE, $opId, 'undo');
        $replay = $service->apply($this->class, self::DATE, $opId, 'undo');

        $this->assertTrue($replay['replayed']);
        $this->assertSame(0, ParticipationEntry::count());
        $this->assertSame(1, ParticipationOperation::where('kind', 'undo')->count());
    }
}
