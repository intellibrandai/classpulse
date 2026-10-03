<?php

namespace Tests\Feature;

use App\Models\ReportCommentDraft;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReportCommentDraftsApiTest extends TestCase
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

    private function one(string $period = 'full', ?Student $student = null, ?SchoolClass $class = null): string
    {
        return '/api/classes/'.($class ?? $this->class)->id.'/students/'.($student ?? $this->alex)->id.'/report-comments/'.$period;
    }

    private function list(?string $period = null, ?SchoolClass $class = null): string
    {
        return '/api/classes/'.($class ?? $this->class)->id.'/report-comments'.($period === null ? '' : '?period='.$period);
    }

    private function configureQuarter(string $kind): void
    {
        $this->class->academicPeriods()->create(['kind' => $kind, 'label' => strtoupper($kind), 'starts_on' => '2026-09-08', 'ends_on' => '2026-11-13']);
    }

    public function test_put_creates_then_updates_one_draft_per_student_and_period(): void
    {
        $this->putJson($this->one(), ['body' => '  Alex is thoughtful.  '])
            ->assertOk()
            ->assertJsonPath('data.draft.body', 'Alex is thoughtful.')
            ->assertJsonPath('data.deleted', false);
        $this->assertNotNull($this->getJson($this->list())->json('data.drafts.'.$this->alex->id.'.updated_at'));

        $this->putJson($this->one(), ['body' => 'Revised'])->assertOk()->assertJsonPath('data.draft.body', 'Revised');

        $this->assertSame(1, ReportCommentDraft::count());
        $this->assertSame($this->class->id, ReportCommentDraft::sole()->school_class_id);
    }

    public function test_index_returns_the_drafts_of_that_class_and_period_keyed_by_student_id(): void
    {
        $this->configureQuarter('q1');
        $bea = Student::factory()->create(['school_class_id' => $this->class->id]);
        $this->putJson($this->one('full'), ['body' => 'alex full']);
        $this->putJson($this->one('full', $bea), ['body' => 'bea full']);
        $this->putJson($this->one('q1'), ['body' => 'alex q1']);

        $full = $this->getJson($this->list('full'))->assertOk()->assertJsonPath('data.period', 'full');
        $this->assertSame(['alex full', 'bea full'], [$full->json('data.drafts.'.$this->alex->id.'.body'), $full->json('data.drafts.'.$bea->id.'.body')]);
        $this->getJson($this->list('q1'))->assertOk()->assertJsonCount(1, 'data.drafts')
            ->assertJsonPath('data.drafts.'.$this->alex->id.'.body', 'alex q1');
        $this->getJson($this->list())->assertOk()->assertJsonPath('data.period', 'full')->assertJsonCount(2, 'data.drafts');
    }

    public function test_an_empty_list_is_a_json_object(): void
    {
        $this->assertSame('{"data":{"period":"full","drafts":{}}}', $this->getJson($this->list('full'))->assertOk()->getContent());
    }

    public function test_blank_body_deletes_the_draft_and_delete_is_idempotent(): void
    {
        $this->putJson($this->one(), ['body' => 'x']);
        $this->putJson($this->one(), ['body' => " \n "])->assertOk()->assertJsonPath('data.draft', null)->assertJsonPath('data.deleted', true);
        $this->assertSame(0, ReportCommentDraft::count());
        $this->putJson($this->one(), ['body' => ''])->assertOk()->assertJsonPath('data.deleted', false);

        $this->putJson($this->one(), ['body' => 'again']);
        $this->deleteJson($this->one())->assertOk()->assertJsonPath('data.deleted', true);
        $this->deleteJson($this->one())->assertOk()->assertJsonPath('data.deleted', false);
        $this->assertSame(0, ReportCommentDraft::count());
    }

    public function test_body_limits_and_period_validation(): void
    {
        $this->putJson($this->one(), ['body' => str_repeat('é', 4000)])->assertOk();
        $this->putJson($this->one(), ['body' => str_repeat('a', 4001)])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->putJson($this->one(), [])->assertStatus(422);
        $this->putJson($this->one(), ['body' => ['x']])->assertStatus(422);
        $this->putJson($this->one('q3'), ['body' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->deleteJson($this->one('bogus'))->assertStatus(422);
        $this->getJson($this->list('bogus'))->assertStatus(422);
        $this->assertSame(1, ReportCommentDraft::count());
    }

    public function test_quarters_are_only_valid_when_configured(): void
    {
        $this->putJson($this->one('q1'), ['body' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->getJson($this->list('q2'))->assertStatus(422);
        $this->deleteJson($this->one('q1'))->assertStatus(422);

        $this->configureQuarter('q1');
        $this->putJson($this->one('q1'), ['body' => 'quarter draft'])->assertOk();
        $this->getJson($this->list('q1'))->assertOk();
        $this->putJson($this->one('q2'), ['body' => 'x'])->assertStatus(422);
        $this->assertSame(['q1'], ReportCommentDraft::pluck('period')->all());
    }

    public function test_student_of_another_class_is_a_404_for_every_verb_and_nothing_changes(): void
    {
        $otherClass = SchoolClass::factory()->create();
        $stranger = Student::factory()->create(['school_class_id' => $otherClass->id]);
        ReportCommentDraft::factory()->create(['school_class_id' => $otherClass->id, 'student_id' => $stranger->id, 'body' => 'private']);

        $this->putJson($this->one('full', $stranger), ['body' => 'hijack'])->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->deleteJson($this->one('full', $stranger))->assertNotFound();
        $this->putJson('/api/classes/'.$otherClass->id.'/students/'.$this->alex->id.'/report-comments/full', ['body' => 'x'])->assertNotFound();
        $this->getJson('/api/classes/9999/report-comments')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->getJson($this->list('full'))->assertOk()->assertJsonCount(0, 'data.drafts');
        $this->getJson($this->list('full', $otherClass))->assertOk()->assertJsonCount(1, 'data.drafts');

        $this->assertSame('private', ReportCommentDraft::sole()->body);
    }

    public function test_archived_students_keep_their_drafts(): void
    {
        $this->putJson($this->one(), ['body' => 'kept']);
        $this->post('/students/'.$this->alex->id.'/archive');
        $this->assertTrue($this->alex->fresh()->isArchived());

        $this->getJson($this->list('full'))->assertJsonPath('data.drafts.'.$this->alex->id.'.body', 'kept');
        $this->putJson($this->one(), ['body' => 'still editable'])->assertOk();
        $this->post('/students/'.$this->alex->id.'/restore');
        $this->assertSame('still editable', ReportCommentDraft::sole()->body);
    }

    public function test_drafts_persist_across_sessions(): void
    {
        $this->putJson($this->one(), ['body' => 'survives login']);
        $this->post('/logout');
        $this->assertGuest();
        $this->actingAs(User::factory()->create());

        $this->getJson($this->list('full'))->assertJsonPath('data.drafts.'.$this->alex->id.'.body', 'survives login');
    }

    public function test_deleting_the_class_removes_its_drafts_and_only_its_drafts(): void
    {
        $keep = SchoolClass::factory()->create(['name' => 'HNC 3C']);
        $kept = Student::factory()->create(['school_class_id' => $keep->id]);
        ReportCommentDraft::factory()->create(['school_class_id' => $keep->id, 'student_id' => $kept->id]);
        $this->putJson($this->one(), ['body' => 'gone with class']);

        $this->delete('/classes/'.$this->class->id, ['confirm_name' => $this->class->name])->assertRedirect('/roster');

        $this->assertSame(1, ReportCommentDraft::count());
        $this->assertSame($keep->id, ReportCommentDraft::sole()->school_class_id);
    }

    public function test_a_draft_cannot_reference_a_student_of_another_class_or_repeat_a_period(): void
    {
        $otherClass = SchoolClass::factory()->create();
        try {
            ReportCommentDraft::create(['school_class_id' => $otherClass->id, 'student_id' => $this->alex->id, 'period' => 'full', 'body' => 'x']);
            $this->fail('A draft must not point at another class\'s student.');
        } catch (QueryException) {
            $this->assertSame(0, ReportCommentDraft::count());
        }

        ReportCommentDraft::create(['school_class_id' => $this->class->id, 'student_id' => $this->alex->id, 'period' => 'full', 'body' => 'x']);
        $this->expectException(QueryException::class);
        ReportCommentDraft::create(['school_class_id' => $this->class->id, 'student_id' => $this->alex->id, 'period' => 'full', 'body' => 'dup']);
    }

    public function test_guests_get_a_401_json_and_routes_sit_behind_session_auth_and_csrf(): void
    {
        auth()->logout();
        $this->getJson($this->list('full'))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->putJson($this->one(), ['body' => 'x'])->assertStatus(401);
        $this->deleteJson($this->one())->assertStatus(401);

        foreach (['GET' => '/api/classes/1/report-comments', 'PUT' => '/api/classes/1/students/1/report-comments/full', 'DELETE' => '/api/classes/1/students/1/report-comments/full'] as $method => $uri) {
            $route = Route::getRoutes()->match(request()->create($uri, $method));
            $middleware = implode(' ', array_map('strval', Route::gatherRouteMiddleware($route)));
            $this->assertStringContainsString('Authenticate', $middleware);
            $this->assertMatchesRegularExpression('/Csrf|RequestForgery/', $middleware);
        }
        $this->assertSame(0, ReportCommentDraft::count());
    }
}
