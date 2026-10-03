<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StudentNotesApiTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    private Student $alex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->class = SchoolClass::factory()->create();
        $this->alex = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Alex Rivera']);
    }

    private function url(?string $date = null, ?Student $student = null, ?SchoolClass $class = null): string
    {
        return '/api/classes/'.($class ?? $this->class)->id.'/students/'.($student ?? $this->alex)->id.'/notes'.($date ? '/'.$date : '');
    }

    public function test_put_creates_then_updates_one_note_per_student_and_day(): void
    {
        $this->putJson($this->url('2026-10-19'), ['body' => '  Led the group.  '])
            ->assertOk()
            ->assertJsonPath('data.note.date', '2026-10-19')
            ->assertJsonPath('data.note.body', 'Led the group.')
            ->assertJsonPath('data.deleted', false);

        $this->putJson($this->url('2026-10-19'), ['body' => 'Revised remark'])
            ->assertOk()->assertJsonPath('data.note.body', 'Revised remark');

        $this->assertSame(1, StudentNote::count());
        $this->assertSame($this->class->id, StudentNote::first()->school_class_id);
    }

    public function test_index_lists_all_notes_newest_first_for_that_student_only(): void
    {
        $other = Student::factory()->create(['school_class_id' => $this->class->id]);
        $this->putJson($this->url('2026-10-19'), ['body' => 'old']);
        $this->putJson($this->url('2026-10-21'), ['body' => 'new']);
        $this->putJson($this->url('2026-10-20', $other), ['body' => 'not alex']);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'data.notes')
            ->assertJsonPath('data.notes.0.date', '2026-10-21')
            ->assertJsonPath('data.notes.0.body', 'new')
            ->assertJsonPath('data.notes.1.date', '2026-10-19');

        $this->assertNotNull($this->getJson($this->url())->json('data.notes.0.updated_at'));
        $this->getJson($this->url(null, Student::factory()->create(['school_class_id' => $this->class->id])))
            ->assertOk()->assertJsonPath('data.notes', []);
    }

    public function test_empty_or_whitespace_body_deletes_the_note(): void
    {
        $this->putJson($this->url('2026-10-19'), ['body' => 'x']);

        $this->putJson($this->url('2026-10-19'), ['body' => "   \n"])
            ->assertOk()->assertJsonPath('data.note', null)->assertJsonPath('data.deleted', true);
        $this->assertSame(0, StudentNote::count());

        $this->putJson($this->url('2026-10-19'), ['body' => ''])
            ->assertOk()->assertJsonPath('data.deleted', false);
    }

    public function test_delete_removes_the_note_and_is_idempotent(): void
    {
        $this->putJson($this->url('2026-10-19'), ['body' => 'x']);

        $this->deleteJson($this->url('2026-10-19'))->assertOk()->assertJsonPath('data.deleted', true);
        $this->deleteJson($this->url('2026-10-19'))->assertOk()->assertJsonPath('data.deleted', false);
        $this->assertSame(0, StudentNote::count());
    }

    public function test_body_limits_and_date_validation(): void
    {
        $this->putJson($this->url('2026-10-19'), ['body' => str_repeat('é', 2000)])->assertOk();
        $this->putJson($this->url('2026-10-20'), ['body' => str_repeat('a', 2001)])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->putJson($this->url('2026-10-21'), [])->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->putJson($this->url('2026-10-21'), ['body' => ['x']])->assertStatus(422);
        $this->putJson($this->url('2026-02-30'), ['body' => 'x'])
            ->assertStatus(422)->assertJsonPath('error.message', 'The date is not a valid calendar date.');
        $this->deleteJson($this->url('2026-13-01'))->assertStatus(422);
        $this->getJson($this->url('2026-10-19x'))->assertNotFound();
        $this->assertSame(1, StudentNote::count());
    }

    public function test_student_of_another_class_is_a_404_for_every_verb_and_nothing_changes(): void
    {
        $otherClass = SchoolClass::factory()->create();
        $stranger = Student::factory()->create(['school_class_id' => $otherClass->id]);
        StudentNote::factory()->create(['school_class_id' => $otherClass->id, 'student_id' => $stranger->id, 'note_date' => '2026-10-19', 'body' => 'private']);

        $wrong = $this->url('2026-10-19', $stranger);
        $this->getJson($this->url(null, $stranger))->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->putJson($wrong, ['body' => 'hijack'])->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->deleteJson($wrong)->assertNotFound();
        $this->getJson('/api/classes/'.$otherClass->id.'/students/'.$this->alex->id.'/notes')->assertNotFound();
        $this->putJson('/api/classes/9999/students/'.$this->alex->id.'/notes/2026-10-19', ['body' => 'x'])->assertNotFound();

        $this->assertSame('private', StudentNote::sole()->body);
    }

    public function test_archived_students_keep_and_can_edit_their_notes(): void
    {
        $this->putJson($this->url('2026-10-19'), ['body' => 'kept']);

        $this->post('/students/'.$this->alex->id.'/archive');
        $this->assertTrue($this->alex->fresh()->isArchived());
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.notes.0.body', 'kept');
        $this->putJson($this->url('2026-10-20'), ['body' => 'still writable'])->assertOk();

        $this->post('/students/'.$this->alex->id.'/restore');
        $this->assertSame(2, StudentNote::where('student_id', $this->alex->id)->count());
    }

    public function test_notes_persist_across_sessions(): void
    {
        $this->putJson($this->url('2026-10-19'), ['body' => 'survives login']);

        $this->post('/logout');
        $this->assertGuest();
        $this->actingAs(User::factory()->create());

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.notes.0.body', 'survives login');
    }

    public function test_deleting_the_class_removes_its_notes_and_only_its_notes(): void
    {
        $keep = SchoolClass::factory()->create(['name' => 'HNC 3C']);
        $kept = Student::factory()->create(['school_class_id' => $keep->id]);
        StudentNote::factory()->create(['school_class_id' => $keep->id, 'student_id' => $kept->id]);
        $this->putJson($this->url('2026-10-19'), ['body' => 'gone with class']);
        ParticipationEntry::factory()->create(['school_class_id' => $this->class->id, 'student_id' => $this->alex->id]);

        $this->delete('/classes/'.$this->class->id, ['confirm_name' => $this->class->name])->assertRedirect('/roster');

        $this->assertSame(1, StudentNote::count());
        $this->assertSame($keep->id, StudentNote::sole()->school_class_id);
    }

    public function test_a_note_cannot_reference_a_student_of_another_class_at_the_database(): void
    {
        $otherClass = SchoolClass::factory()->create();

        $this->expectException(QueryException::class);
        StudentNote::create(['school_class_id' => $otherClass->id, 'student_id' => $this->alex->id, 'note_date' => '2026-10-19', 'body' => 'x']);
    }

    public function test_guests_get_a_401_json_and_routes_sit_behind_session_auth_and_csrf(): void
    {
        auth()->logout();
        $this->getJson($this->url())->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->putJson($this->url('2026-10-19'), ['body' => 'x'])->assertStatus(401);
        $this->deleteJson($this->url('2026-10-19'))->assertStatus(401);

        foreach (['GET' => '/notes', 'PUT' => '/notes/{date}', 'DELETE' => '/notes/{date}'] as $method => $suffix) {
            $route = Route::getRoutes()->match(request()->create('/api/classes/1/students/1'.str_replace('{date}', '2026-10-19', $suffix), $method));
            $middleware = implode(' ', array_map('strval', Route::gatherRouteMiddleware($route)));
            $this->assertStringContainsString('Authenticate', $middleware);
            $this->assertMatchesRegularExpression('/Csrf|RequestForgery/', $middleware);
        }
        $this->assertSame(0, StudentNote::count());
    }
}
