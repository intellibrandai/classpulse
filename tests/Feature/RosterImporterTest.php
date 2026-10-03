<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\RosterImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RosterImporterTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    private RosterImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'roster_cap' => 30]);
        $this->importer = new RosterImporter;
    }

    private function file(string $content, string $name = 'roster.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_bom_semicolon_file_previews_joined_names_as_ok(): void
    {
        $csv = "\xEF\xBB\xBFfirst_name;last_name;student_number\r\nAlex;Rivera;S-1\r\nJordan;  Lee ;S-2\r\n";

        $result = $this->importer->preview($this->class, $this->file($csv), null);

        $this->assertSame(40, strlen($result['token']));
        $this->assertSame([
            ['row' => 1, 'name' => 'Alex Rivera', 'student_number' => 'S-1', 'status' => 'OK', 'reason' => ''],
            ['row' => 2, 'name' => 'Jordan Lee', 'student_number' => 'S-2', 'status' => 'OK', 'reason' => ''],
        ], $result['rows']);
    }

    public function test_name_header_and_tab_delimiter_are_recognised(): void
    {
        $result = $this->importer->preview($this->class, $this->file("Name\tStudent_Number\nAlex Rivera\tS-1\n"), null);

        $this->assertSame('Alex Rivera', $result['rows'][0]['name']);
        $this->assertSame('S-1', $result['rows'][0]['student_number']);
    }

    public function test_pasted_names_one_per_line(): void
    {
        $result = $this->importer->preview($this->class, null, "Alex Rivera\n\n  Jordan   Lee  \n");

        $this->assertCount(2, $result['rows']);
        $this->assertSame('Jordan Lee', $result['rows'][1]['name']);
        $this->assertSame(2, $result['rows'][1]['row']);
    }

    public function test_matches_and_repeats_are_warnings(): void
    {
        Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Alex Rivera', 'student_number' => 'S-9']);

        $rows = $this->importer->preview($this->class, $this->file("name,student_number\nalex  RIVERA,\nNew Kid,S-9\nSam Cole,\nsam cole,\n"), null)['rows'];

        $this->assertSame('WARNING', $rows[0]['status']);
        $this->assertSame('Matches an existing student.', $rows[0]['reason']);
        $this->assertSame('WARNING', $rows[1]['status']);
        $this->assertSame('OK', $rows[2]['status']);
        $this->assertSame('WARNING', $rows[3]['status']);
        $this->assertSame('Repeats row 3.', $rows[3]['reason']);
    }

    public function test_empty_long_and_over_cap_rows_are_errors(): void
    {
        $this->class->update(['roster_cap' => 2]);
        $csv = "name\n\"\"\n".str_repeat('x', 121)."\nA One\nB Two\nC Three\n";

        $rows = $this->importer->preview($this->class, $this->file($csv), null)['rows'];

        $this->assertSame('Name is empty.', $rows[0]['reason']);
        $this->assertSame('Name is longer than 120 characters.', $rows[1]['reason']);
        $this->assertSame(['OK', 'OK', 'ERROR'], array_column(array_slice($rows, 2), 'status'));
        $this->assertStringStartsWith('Over capacity', $rows[4]['reason']);
    }

    public function test_commit_creates_selected_rows_and_never_error_rows(): void
    {
        $preview = $this->importer->preview($this->class, $this->file("name\nAlex Rivera\n\"\"\nJordan Lee\n"), null);

        $created = $this->importer->commit($this->class, $preview['token'], [1, 2]);

        $this->assertSame(1, $created);
        $this->assertSame(['Alex Rivera'], Student::where('school_class_id', $this->class->id)->pluck('display_name')->all());
    }

    public function test_unknown_token_or_other_class_raises_and_creates_nothing(): void
    {
        $preview = $this->importer->preview($this->class, null, "Alex Rivera\n");
        $other = SchoolClass::factory()->create();

        foreach ([[$this->class, 'nope'], [$other, $preview['token']]] as [$class, $token]) {
            try {
                $this->importer->commit($class, $token, [1]);
                $this->fail('ValidationException expected');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('token', $e->errors());
            }
        }
        $this->assertSame(0, Student::count());
    }

    public function test_bad_file_and_input_combinations_raise(): void
    {
        $cases = [
            fn () => $this->importer->preview($this->class, UploadedFile::fake()->createWithContent('r.csv', str_repeat('a', 257 * 1024)), null),
            fn () => $this->importer->preview($this->class, $this->file('name', 'roster.xlsx'), null),
            fn () => $this->importer->preview($this->class, $this->file('name'), 'Alex'),
            fn () => $this->importer->preview($this->class, null, null),
        ];
        foreach ($cases as $case) {
            try {
                $case();
                $this->fail('ValidationException expected');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cap_reached_between_preview_and_commit_creates_nothing(): void
    {
        $this->class->update(['roster_cap' => 2]);
        $preview = $this->importer->preview($this->class, null, "A One\nB Two\n");
        Student::factory()->create(['school_class_id' => $this->class->id]);

        try {
            $this->importer->commit($this->class, $preview['token'], [1, 2]);
            $this->fail('ValidationException expected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('roster_cap', $e->errors());
        }
        $this->assertSame(1, Student::count());
    }
}
