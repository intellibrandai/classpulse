<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentProfileTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    private Student $alex;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 10:00', 'America/Toronto'));
        $this->actingAs(User::factory()->create());
        $this->class = SchoolClass::factory()->create();
        $this->alex = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Alex Rivera']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_update_saves_preferred_name_and_observations_keeping_the_id(): void
    {
        $this->put('/students/'.$this->alex->id, [
            'display_name' => 'Alex Rivera', 'preferred_name' => 'Ali', 'student_number' => 'S-1', 'observations' => 'Sits near the front.',
        ])->assertRedirect('/roster?class='.$this->class->id);

        $fresh = $this->alex->fresh();
        $this->assertSame(['Ali', 'S-1', 'Sits near the front.'], [$fresh->preferred_name, $fresh->student_number, $fresh->observations]);
    }

    public function test_blank_values_clear_the_fields_and_omitted_ones_are_kept(): void
    {
        $this->alex->update(['preferred_name' => 'Ali', 'observations' => 'x']);

        $this->put('/students/'.$this->alex->id, ['display_name' => 'Alex Rivera'])->assertSessionHasNoErrors();
        $this->assertSame('Ali', $this->alex->fresh()->preferred_name);

        $this->put('/students/'.$this->alex->id, ['display_name' => 'Alex Rivera', 'preferred_name' => '', 'observations' => ' ']);
        $this->assertNull($this->alex->fresh()->preferred_name);
        $this->assertNull($this->alex->fresh()->observations);
    }

    public function test_limits_are_80_and_2000_characters(): void
    {
        $base = ['display_name' => 'Alex Rivera'];

        $this->put('/students/'.$this->alex->id, $base + ['preferred_name' => str_repeat('a', 81)])->assertSessionHasErrors('preferred_name');
        $this->put('/students/'.$this->alex->id, $base + ['observations' => str_repeat('a', 2001)])->assertSessionHasErrors('observations');
        $this->put('/students/'.$this->alex->id, $base + ['preferred_name' => str_repeat('a', 80), 'observations' => str_repeat('b', 2000)])->assertSessionHasNoErrors();
    }

    public function test_new_students_can_carry_a_preferred_name(): void
    {
        $this->post('/classes/'.$this->class->id.'/students', ['display_name' => 'Jordan Lee', 'preferred_name' => 'JJ'])->assertSessionHasNoErrors();

        $this->assertSame('JJ', Student::where('display_name', 'Jordan Lee')->sole()->preferred_name);
    }

    public function test_archive_and_restore_are_reversible_and_keep_the_profile(): void
    {
        $this->alex->update(['preferred_name' => 'Ali', 'observations' => 'note']);

        $this->post('/students/'.$this->alex->id.'/archive');
        $archived = $this->alex->fresh();
        $this->assertTrue($archived->isArchived());
        $this->assertSame('Oct 26, 2026', $archived->archivedOn());

        $this->post('/students/'.$this->alex->id.'/restore');
        $restored = $this->alex->fresh();
        $this->assertFalse($restored->isArchived());
        $this->assertNull($restored->archivedOn());
        $this->assertSame(['Ali', 'note'], [$restored->preferred_name, $restored->observations]);
    }

    public function test_roster_page_gets_archived_students_newest_first_with_dates_plus_roster_stats_and_periods(): void
    {
        $older = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Zed Old', 'archived_at' => '2026-10-01 09:00:00']);
        $newer = Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Amy New', 'archived_at' => '2026-10-20 09:00:00']);

        $response = $this->get('/roster?class='.$this->class->id)->assertOk();

        $archived = $response->viewData('archivedStudents');
        $this->assertSame([$newer->id, $older->id], $archived->pluck('id')->all());
        $this->assertSame('Oct 20, 2026', $archived->first()->archivedOn());
        $this->assertArrayHasKey($this->alex->id, $response->viewData('rosterStats'));
        $this->assertArrayNotHasKey($newer->id, $response->viewData('rosterStats'));
        $this->assertArrayHasKey('full', $response->viewData('periods'));
    }
}
