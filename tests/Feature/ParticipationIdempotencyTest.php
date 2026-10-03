<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\ParticipationOperation;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\ParticipationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParticipationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-21';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 10:00', 'America/Toronto'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_fifty_distinct_op_ids_store_fifty_points(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->create(['school_class_id' => $class->id]);
        $service = app(ParticipationService::class);

        for ($i = 0; $i < 50; $i++) {
            $service->apply($class, self::DATE, (string) Str::uuid(), 'increment', $student->id);
        }

        $this->assertSame(50, ParticipationEntry::where('student_id', $student->id)->value('points'));
        $this->assertSame(50, ParticipationOperation::count());
    }

    public function test_replayed_op_id_changes_nothing_and_is_flagged(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->create(['school_class_id' => $class->id]);
        $service = app(ParticipationService::class);
        $opId = (string) Str::uuid();

        $first = $service->apply($class, self::DATE, $opId, 'increment', $student->id);
        $replay = $service->apply($class, self::DATE, $opId, 'increment', $student->id);

        $this->assertFalse($first['replayed']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame(1, $replay['entries'][0]['points']);
        $this->assertSame($first['day_version'], $replay['day_version']);
        $this->assertSame(1, ParticipationOperation::count());
        $this->assertSame(1, ParticipationEntry::first()->points);
    }

    public function test_class_row_is_locked_before_the_first_write(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->create(['school_class_id' => $class->id]);

        DB::enableQueryLog();
        app(ParticipationService::class)->apply($class, self::DATE, (string) Str::uuid(), 'increment', $student->id);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $lock = null;
        $insert = null;
        foreach ($queries as $i => $sql) {
            $sql = strtolower($sql);
            if ($lock === null && str_contains($sql, 'school_classes') && str_contains($sql, 'for update')) {
                $lock = $i;
            }
            if ($insert === null && str_starts_with($sql, 'insert')) {
                $insert = $i;
            }
        }

        $this->assertNotNull($lock, 'No locking query on school_classes');
        $this->assertNotNull($insert, 'No insert query');
        $this->assertLessThan($insert, $lock);
    }
}
