<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassPeriodSettingsTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->class = SchoolClass::factory()->create([
            'name' => 'HNL 2O', 'semester_start' => '2026-09-08', 'semester_end' => '2027-01-29',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'HNL 2O',
            'title' => 'Nutrition & Health',
            'subject_description' => 'Food and Nutrition',
            'period_label' => 'Period 2',
            'room' => 'Room 114',
            'schedule' => 'Period 2 (10:15 - 11:35)',
            'roster_cap' => 30,
            'semester_start' => '2026-09-08',
            'semester_end' => '2027-01-29',
            'q1_label' => 'Midterm',
            'q1_start' => '2026-09-08',
            'q1_end' => '2026-11-13',
            'q2_label' => '',
            'q2_start' => '2026-11-16',
            'q2_end' => '2027-01-29',
        ], $overrides);
    }

    public function test_saves_class_details_and_both_periods(): void
    {
        $this->from('/roster')->put('/classes/'.$this->class->id, $this->payload())->assertRedirect('/roster');

        $class = $this->class->fresh();
        $this->assertSame('Nutrition & Health', $class->title);
        $this->assertSame('Room 114', $class->room);
        $this->assertSame('Period 2 (10:15 - 11:35)', $class->schedule);
        $q1 = $class->academicPeriods()->where('kind', 'q1')->sole();
        $this->assertSame(['Midterm', '2026-09-08', '2026-11-13'], [$q1->label, $q1->starts_on->format('Y-m-d'), $q1->ends_on->format('Y-m-d')]);
        $this->assertSame('Q2 / Finals', $class->academicPeriods()->where('kind', 'q2')->sole()->label, 'blank label uses the default');
    }

    public function test_saving_again_updates_in_place_and_blank_dates_remove_a_period(): void
    {
        $this->put('/classes/'.$this->class->id, $this->payload());
        $firstId = $this->class->academicPeriods()->where('kind', 'q1')->value('id');

        $this->put('/classes/'.$this->class->id, $this->payload(['q1_end' => '2026-11-06', 'q2_start' => null, 'q2_end' => null]));

        $this->assertSame(1, $this->class->academicPeriods()->count());
        $q1 = $this->class->academicPeriods()->where('kind', 'q1')->sole();
        $this->assertSame($firstId, $q1->id);
        $this->assertSame('2026-11-06', $q1->ends_on->format('Y-m-d'));
    }

    public function test_class_update_without_period_fields_leaves_periods_untouched(): void
    {
        $this->put('/classes/'.$this->class->id, $this->payload());

        $this->put('/classes/'.$this->class->id, [
            'name' => 'HNL 2O', 'roster_cap' => 25, 'semester_start' => '2026-09-08', 'semester_end' => '2027-01-29',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->class->academicPeriods()->count());
        $this->assertSame(25, $this->class->fresh()->roster_cap);
        $this->assertSame('Room 114', $this->class->fresh()->room);
    }

    public function test_invalid_period_settings_are_rejected_field_by_field_and_save_nothing(): void
    {
        $id = $this->class->id;

        $this->put("/classes/{$id}", $this->payload(['q1_start' => '2026-11-14', 'q1_end' => '2026-11-13']))->assertSessionHasErrors('q1_end');
        $this->put("/classes/{$id}", $this->payload(['q1_start' => '2026-09-01']))->assertSessionHasErrors('q1_start');
        $this->put("/classes/{$id}", $this->payload(['q2_end' => '2027-02-05']))->assertSessionHasErrors('q2_end');
        $this->put("/classes/{$id}", $this->payload(['q2_start' => '2026-11-13']))->assertSessionHasErrors('q2_start');
        $this->put("/classes/{$id}", $this->payload(['q2_start' => null]))->assertSessionHasErrors('q2_start');
        $this->put("/classes/{$id}", $this->payload(['q1_start' => '08/09/2026']))->assertSessionHasErrors('q1_start');
        $this->put("/classes/{$id}", $this->payload(['q1_label' => str_repeat('x', 41)]))->assertSessionHasErrors('q1_label');
        $this->put("/classes/{$id}", $this->payload(['title' => str_repeat('x', 121)]))->assertSessionHasErrors('title');
        $this->put("/classes/{$id}", $this->payload(['room' => str_repeat('x', 61)]))->assertSessionHasErrors('room');
        $this->put("/classes/{$id}", $this->payload(['schedule' => str_repeat('x', 81)]))->assertSessionHasErrors('schedule');

        $this->assertSame(0, AcademicPeriod::count());
        $this->assertNull($this->class->fresh()->title);
    }

    public function test_moving_the_semester_must_keep_stored_periods_inside_it(): void
    {
        $this->put('/classes/'.$this->class->id, $this->payload());

        $this->put('/classes/'.$this->class->id, [
            'name' => 'HNL 2O', 'roster_cap' => 30, 'semester_start' => '2026-10-01', 'semester_end' => '2027-01-29',
        ])->assertSessionHasErrors('q1_start');

        $this->assertSame('2026-09-08', $this->class->fresh()->semester_start->format('Y-m-d'));
    }

    public function test_quarters_are_free_when_the_class_has_no_semester_dates(): void
    {
        $this->class->update(['semester_start' => null, 'semester_end' => null]);

        $this->put('/classes/'.$this->class->id, $this->payload(['semester_start' => null, 'semester_end' => null]))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $this->class->academicPeriods()->count());
    }

    public function test_create_class_accepts_the_new_detail_fields(): void
    {
        $this->post('/classes', [
            'name' => 'HLS 3O', 'title' => 'Living Skills', 'room' => 'Lab 2', 'schedule' => 'Period 4', 'roster_cap' => 30,
        ])->assertSessionHasNoErrors();

        $created = SchoolClass::where('name', 'HLS 3O')->sole();
        $this->assertSame(['Living Skills', 'Lab 2', 'Period 4'], [$created->title, $created->room, $created->schedule]);
    }

    public function test_deleting_the_class_removes_its_periods(): void
    {
        $this->put('/classes/'.$this->class->id, $this->payload());
        $other = SchoolClass::factory()->create();
        AcademicPeriod::factory()->create(['school_class_id' => $other->id]);

        $this->delete('/classes/'.$this->class->id, ['confirm_name' => 'HNL 2O']);

        $this->assertSame(1, AcademicPeriod::count());
    }
}
