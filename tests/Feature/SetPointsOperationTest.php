<?php

namespace Tests\Feature;

use App\Exceptions\ParticipationException;
use App\Models\ParticipationEntry;
use App\Models\ParticipationOperation;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\ParticipationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * set_points (absolute value) and clear (back to Not recorded), used by the Weekly Matrix quick editor.
 */
class SetPointsOperationTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-20';

    private SchoolClass $class;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 10:00', 'America/Toronto'));
        $this->class = SchoolClass::factory()->create();
        $this->student = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Alex Rivera']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function service(): ParticipationService
    {
        return app(ParticipationService::class);
    }

    private function set(int $points, ?Student $student = null, string $date = self::DATE, ?string $opId = null): array
    {
        return $this->service()->apply($this->class, $date, $opId ?? (string) Str::uuid(), 'set_points', ($student ?? $this->student)->id, $points);
    }

    private function op(string $kind, ?Student $student = null, string $date = self::DATE): array
    {
        return $this->service()->apply($this->class, $date, (string) Str::uuid(), $kind, ($student ?? $this->student)->id);
    }

    private function entry(?Student $student = null, string $date = self::DATE): ?ParticipationEntry
    {
        return ParticipationEntry::where('student_id', ($student ?? $this->student)->id)->where('work_date', $date)->first();
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

    public function test_set_points_creates_a_present_entry_for_a_not_recorded_student(): void
    {
        $day = $this->set(7);

        $this->assertSame('present', $this->entry()->status);
        $this->assertSame(7, $this->entry()->points);
        $this->assertNull($this->entry()->restore_points);
        $this->assertSame(7, $day['summary']['total_points']);
        $this->assertTrue($day['can_undo']);
    }

    public function test_set_points_replaces_the_value_of_a_present_entry_and_accepts_zero_and_99(): void
    {
        $this->set(4);
        $this->set(0);
        $this->assertSame(0, $this->entry()->points);
        $this->set(99);
        $this->assertSame(99, $this->entry()->points);
        $this->assertSame(3, $this->entry()->revision);
    }

    public function test_set_points_bounds_are_enforced(): void
    {
        $this->assertRejected('points_min', 422, fn () => $this->set(-1));
        $this->assertRejected('points_max', 422, fn () => $this->set(100));
        $this->assertNull($this->entry());
        $this->assertSame(0, ParticipationOperation::count());
    }

    public function test_set_points_on_an_absent_student_is_rejected_with_student_absent(): void
    {
        $this->op('absent_on');

        $this->assertRejected('student_absent', 409, fn () => $this->set(3));
        $this->assertSame('absent', $this->entry()->status);
        $this->assertSame(1, ParticipationOperation::count());
    }

    public function test_setting_the_same_value_is_rejected_and_writes_nothing(): void
    {
        $this->set(3);

        $this->assertRejected('unchanged', 409, fn () => $this->set(3));
        $this->assertSame(1, ParticipationOperation::count());
    }

    public function test_set_points_requires_a_value_and_ignores_no_other_student(): void
    {
        $this->assertRejected('validation', 422, fn () => $this->service()->apply($this->class, self::DATE, (string) Str::uuid(), 'set_points', $this->student->id, null));
        $other = Student::factory()->create(['school_class_id' => SchoolClass::factory()->create()->id]);
        $this->assertRejected('not_found', 404, fn () => $this->set(2, $other));
    }

    public function test_replayed_op_id_changes_nothing(): void
    {
        $opId = (string) Str::uuid();
        $this->set(5, null, self::DATE, $opId);
        $replay = $this->set(9, null, self::DATE, $opId);

        $this->assertTrue($replay['replayed']);
        $this->assertSame(5, $this->entry()->points);
        $this->assertSame(1, ParticipationOperation::count());
    }

    public function test_undo_restores_the_previous_value_and_the_not_recorded_state(): void
    {
        $this->set(4);
        $this->set(8);
        $this->service()->apply($this->class, self::DATE, (string) Str::uuid(), 'undo');
        $this->assertSame(4, $this->entry()->points);
        $this->service()->apply($this->class, self::DATE, (string) Str::uuid(), 'undo');
        $this->assertNull($this->entry());
    }

    public function test_undo_is_per_date_so_another_day_is_untouched(): void
    {
        $this->set(4, null, '2026-10-19');
        $this->set(6, null, self::DATE);

        $this->service()->apply($this->class, self::DATE, (string) Str::uuid(), 'undo');

        $this->assertNull($this->entry());
        $this->assertSame(4, $this->entry(null, '2026-10-19')->points);
    }

    public function test_set_points_takes_the_class_row_lock_like_the_other_operations(): void
    {
        $locked = false;
        DB::listen(function ($query) use (&$locked) {
            if (str_contains(strtolower($query->sql), 'for update') && str_contains($query->sql, 'school_classes')) {
                $locked = true;
            }
        });

        $this->set(2);

        $this->assertTrue($locked);
    }

    public function test_set_points_is_scoped_to_the_class_of_the_route(): void
    {
        $otherClass = SchoolClass::factory()->create();
        $otherStudent = Student::factory()->create(['school_class_id' => $otherClass->id]);

        $this->assertRejected('not_found', 404, fn () => $this->service()->apply($this->class, self::DATE, (string) Str::uuid(), 'set_points', $otherStudent->id, 3));
        $this->assertSame(0, ParticipationEntry::count());
    }

    public function test_archived_students_cannot_be_set(): void
    {
        $this->student->update(['archived_at' => now()]);

        $this->assertRejected('student_archived', 422, fn () => $this->set(3));
    }

    public function test_clear_returns_a_present_or_absent_student_to_not_recorded_and_undo_brings_it_back(): void
    {
        $this->set(6);
        $this->op('clear');
        $this->assertNull($this->entry());

        $this->service()->apply($this->class, self::DATE, (string) Str::uuid(), 'undo');
        $this->assertSame(6, $this->entry()->points);

        $this->op('absent_on');
        $this->op('clear');
        $this->assertNull($this->entry());
        $this->service()->apply($this->class, self::DATE, (string) Str::uuid(), 'undo');
        $this->assertSame('absent', $this->entry()->status);
        $this->assertSame(6, $this->entry()->restore_points);
    }

    public function test_clear_on_a_not_recorded_student_is_rejected(): void
    {
        $this->assertRejected('not_recorded', 422, fn () => $this->op('clear'));
    }

    public function test_weekend_and_future_dates_are_rejected(): void
    {
        $this->assertRejected('weekend', 422, fn () => $this->set(1, null, '2026-10-24'));
        $this->assertRejected('future_date', 422, fn () => $this->set(1, null, '2026-10-23'));
    }

    // ---- HTTP -----------------------------------------------------------------

    private function send(array $body, ?string $date = null, ?SchoolClass $class = null)
    {
        $this->actingAs(User::factory()->create());

        return $this->postJson('/api/classes/'.($class ?? $this->class)->id.'/days/'.($date ?? self::DATE).'/operations', $body + ['op_id' => (string) Str::uuid()]);
    }

    public function test_api_set_points_returns_the_full_day_state(): void
    {
        $this->send(['kind' => 'set_points', 'student_id' => $this->student->id, 'points' => 12])
            ->assertOk()
            ->assertJsonPath('data.entries.0.status', 'present')
            ->assertJsonPath('data.entries.0.points', 12)
            ->assertJsonPath('data.summary.total_points', 12)
            ->assertJsonPath('data.can_undo', true);
    }

    public function test_api_set_points_accepts_zero(): void
    {
        $this->send(['kind' => 'set_points', 'student_id' => $this->student->id, 'points' => 0])
            ->assertOk()
            ->assertJsonPath('data.entries.0.status', 'present')
            ->assertJsonPath('data.entries.0.points', 0);
    }

    public function test_api_validation_errors(): void
    {
        $this->send(['kind' => 'set_points', 'student_id' => $this->student->id])->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->send(['kind' => 'set_points', 'student_id' => $this->student->id, 'points' => 'lots'])->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->send(['kind' => 'set_points', 'points' => 3])->assertStatus(422)->assertJsonPath('error.code', 'validation');
    }

    public function test_api_bounds_and_absent_errors_carry_the_day_state(): void
    {
        $this->send(['kind' => 'set_points', 'student_id' => $this->student->id, 'points' => 100])
            ->assertStatus(422)->assertJsonPath('error.code', 'points_max')->assertJsonPath('error.day.summary.recorded', 0);
        $this->send(['kind' => 'set_points', 'student_id' => $this->student->id, 'points' => -1])
            ->assertStatus(422)->assertJsonPath('error.code', 'points_min');

        $this->send(['kind' => 'absent_on', 'student_id' => $this->student->id])->assertOk();
        $this->send(['kind' => 'set_points', 'student_id' => $this->student->id, 'points' => 2])
            ->assertStatus(409)->assertJsonPath('error.code', 'student_absent')->assertJsonPath('error.day.entries.0.status', 'absent');
    }

    public function test_api_replay_and_undo_and_clear(): void
    {
        $opId = (string) Str::uuid();
        $body = ['kind' => 'set_points', 'student_id' => $this->student->id, 'points' => 5, 'op_id' => $opId];
        $this->send($body)->assertOk()->assertJsonPath('data.replayed', false);
        $this->send($body)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.entries.0.points', 5);

        $this->send(['kind' => 'clear', 'student_id' => $this->student->id])->assertOk()->assertJsonPath('data.entries.0.status', 'none');
        $this->send(['kind' => 'undo'])->assertOk()->assertJsonPath('data.entries.0.points', 5);
    }

    public function test_api_another_class_student_is_not_found(): void
    {
        $otherStudent = Student::factory()->create(['school_class_id' => SchoolClass::factory()->create()->id]);

        $this->send(['kind' => 'set_points', 'student_id' => $otherStudent->id, 'points' => 3])
            ->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    }
}
