<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'HNL 2O',
            'subject_description' => 'Food and Nutrition',
            'period_label' => 'Period 2',
            'roster_cap' => 30,
            'semester_start' => '2026-09-08',
            'semester_end' => '2027-01-29',
        ], $overrides);
    }

    public function test_teacher_creates_a_class(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->post('/classes', $this->payload());

        $class = SchoolClass::where('name', 'HNL 2O')->firstOrFail();
        $response->assertRedirect('/roster?class='.$class->id);
        $this->assertSame(1, SchoolClass::count());
        $this->assertSame(30, $class->roster_cap);
    }

    public function test_invalid_class_is_rejected_field_by_field(): void
    {
        $this->actingAs(User::factory()->create());
        SchoolClass::factory()->create(['name' => 'HNL 2O']);

        $this->post('/classes', $this->payload())->assertSessionHasErrors('name');
        $this->post('/classes', $this->payload(['name' => 'A', 'roster_cap' => 0]))->assertSessionHasErrors('roster_cap');
        $this->post('/classes', $this->payload(['name' => 'B', 'roster_cap' => config('classpulse.max_roster') + 1]))->assertSessionHasErrors('roster_cap');
        $this->post('/classes', $this->payload([
            'name' => 'C',
            'semester_start' => '2026-09-08',
            'semester_end' => '2026-09-01',
        ]))->assertSessionHasErrors('semester_end');

        $this->assertSame(1, SchoolClass::count());
    }

    public function test_teacher_updates_a_class_keeping_its_id(): void
    {
        $this->actingAs(User::factory()->create());
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O']);

        $this->from('/roster')->put('/classes/'.$class->id, $this->payload(['name' => 'HNL 2O', 'period_label' => 'Period 4']))
            ->assertRedirect('/roster');

        $fresh = $class->fresh();
        $this->assertSame($class->id, $fresh->id);
        $this->assertSame('Period 4', $fresh->period_label);
    }

    public function test_cap_below_active_students_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'roster_cap' => 30]);
        Student::factory()->count(5)->create(['school_class_id' => $class->id]);
        Student::factory()->archived()->create(['school_class_id' => $class->id]);

        $this->put('/classes/'.$class->id, $this->payload(['roster_cap' => 4]))->assertSessionHasErrors('roster_cap');
        $this->assertSame(30, $class->fresh()->roster_cap);

        $this->put('/classes/'.$class->id, $this->payload(['roster_cap' => 5]))->assertSessionDoesntHaveErrors();
        $this->assertSame(5, $class->fresh()->roster_cap);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O']);

        $this->post('/classes', $this->payload(['name' => 'Other']))->assertRedirect('/login');
        $this->put('/classes/'.$class->id, $this->payload(['name' => 'Changed']))->assertRedirect('/login');

        $this->assertSame(1, SchoolClass::count());
        $this->assertSame('HNL 2O', $class->fresh()->name);
    }
}
