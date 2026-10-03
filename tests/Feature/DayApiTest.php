<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\ParticipationOperation;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class DayApiTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-21';

    private SchoolClass $class;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 10:00', 'America/Toronto'));
        $this->actingAs(User::factory()->create());
        $this->class = SchoolClass::factory()->create();
        $this->student = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Alex Rivera']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function post_op(string $kind, ?int $studentId = null, ?string $opId = null, string $date = self::DATE, ?SchoolClass $class = null)
    {
        $body = ['op_id' => $opId ?? (string) Str::uuid(), 'kind' => $kind];
        if ($studentId !== null) {
            $body['student_id'] = $studentId;
        }

        return $this->postJson('/api/classes/'.($class ?? $this->class)->id.'/days/'.$date.'/operations', $body);
    }

    public function test_show_returns_the_day_state(): void
    {
        $this->getJson('/api/classes/'.$this->class->id.'/days/'.self::DATE)
            ->assertOk()
            ->assertJsonPath('data.day_version', 0)
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.can_undo', false)
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.student_id', $this->student->id)
            ->assertJsonPath('data.entries.0.status', 'none')
            ->assertJsonPath('data.summary.active_students', 1)
            ->assertJsonPath('data.summary.not_recorded', 1);
    }

    public function test_operation_updates_the_entry_and_bumps_the_version(): void
    {
        $first = $this->post_op('increment', $this->student->id)->assertOk();
        $second = $this->post_op('increment', $this->student->id)->assertOk()
            ->assertJsonPath('data.entries.0.points', 2)
            ->assertJsonPath('data.can_undo', true);

        $this->assertGreaterThan($first->json('data.day_version'), $second->json('data.day_version'));
    }

    public function test_replayed_op_id_changes_nothing(): void
    {
        $opId = (string) Str::uuid();
        $this->post_op('increment', $this->student->id, $opId)->assertOk()->assertJsonPath('data.replayed', false);

        $this->post_op('increment', $this->student->id, $opId)
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.entries.0.points', 1);

        $this->assertSame(1, ParticipationEntry::first()->points);
        $this->assertSame(1, ParticipationOperation::count());
    }

    public function test_business_errors_carry_code_message_and_day(): void
    {
        $this->post_op('decrement', $this->student->id)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'not_recorded')
            ->assertJsonPath('error.message', 'Nothing is recorded for this student yet.')
            ->assertJsonPath('error.day.summary.active_students', 1);

        $this->post_op('set_zero', $this->student->id)->assertOk();
        $this->post_op('decrement', $this->student->id)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'points_min')
            ->assertJsonPath('error.day.entries.0.points', 0);

        $this->post_op('absent_on', $this->student->id)->assertOk();
        $this->post_op('increment', $this->student->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'student_absent')
            ->assertJsonPath('error.day.entries.0.status', 'absent');
    }

    public function test_batch_kinds_need_no_student_id(): void
    {
        $this->post_op('zero_remaining')->assertOk()->assertJsonPath('data.summary.recorded', 1);
        $this->post_op('undo')->assertOk()->assertJsonPath('data.summary.recorded', 0);
        $this->post_op('undo')->assertStatus(409)->assertJsonPath('error.code', 'nothing_to_undo');
    }

    public function test_weekend_future_and_validation_errors_have_no_day(): void
    {
        $this->post_op('increment', $this->student->id, null, '2026-10-24')
            ->assertStatus(422)->assertJsonPath('error.code', 'weekend')->assertJsonMissingPath('error.day');
        $this->post_op('increment', $this->student->id, null, '2026-10-22')
            ->assertStatus(422)->assertJsonPath('error.code', 'future_date')->assertJsonMissingPath('error.day');
        $this->post_op('increment', $this->student->id, null, '2026-02-30')
            ->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->getJson('/api/classes/'.$this->class->id.'/days/2026-02-30')
            ->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->postJson('/api/classes/'.$this->class->id.'/days/'.self::DATE.'/operations', ['op_id' => (string) Str::uuid(), 'kind' => 'explode'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation')->assertJsonMissingPath('error.day');
        $this->post_op('increment')
            ->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->postJson('/api/classes/'.$this->class->id.'/days/'.self::DATE.'/operations', ['op_id' => 'nope', 'kind' => 'increment', 'student_id' => $this->student->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation');

        $this->assertSame(0, ParticipationOperation::count());
    }

    public function test_other_class_student_or_unknown_class_is_404(): void
    {
        $other = Student::factory()->create();

        $this->post_op('increment', $other->id)->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->getJson('/api/classes/99999/days/'.self::DATE)->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->postJson('/api/classes/99999/days/'.self::DATE.'/operations', ['op_id' => (string) Str::uuid(), 'kind' => 'undo'])
            ->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    }

    public function test_archived_student_is_rejected_with_the_day(): void
    {
        $archived = Student::factory()->archived()->create(['school_class_id' => $this->class->id]);

        $this->post_op('increment', $archived->id)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'student_archived')
            ->assertJsonPath('error.day.summary.active_students', 1);
    }

    public function test_unauthenticated_requests_get_a_401_json_error(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard()->logout();

        $this->getJson('/api/classes/'.$this->class->id.'/days/'.self::DATE)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated')
            ->assertJsonPath('error.message', 'Session expired — sign in again');

        $this->postJson('/api/classes/'.$this->class->id.'/days/'.self::DATE.'/operations', ['op_id' => (string) Str::uuid(), 'kind' => 'undo'])
            ->assertStatus(401);
    }

    public function test_token_mismatch_renders_as_419_csrf_mismatch(): void
    {
        Route::middleware('web')->post('/api/_probe/csrf', fn () => throw new TokenMismatchException);

        $this->postJson('/api/_probe/csrf')
            ->assertStatus(419)
            ->assertJsonPath('error.code', 'csrf_mismatch')
            ->assertJsonPath('error.message', 'Session expired — sign in again');
    }
}
