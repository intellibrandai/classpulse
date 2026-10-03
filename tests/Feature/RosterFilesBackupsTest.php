<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterFilesBackupsTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->class = SchoolClass::factory()->create(['name' => 'HNL 2O']);
        Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Alex Rivera']);
    }

    private function section(): string
    {
        $html = $this->get('/roster?class='.$this->class->id)->assertOk()->getContent();
        $start = strpos($html, 'aria-labelledby="files-title"');
        $this->assertNotFalse($start, 'The Files & backups section is missing.');

        return substr($html, $start, strpos($html, '</section>', $start) - $start);
    }

    public function test_section_has_the_exact_texts_and_the_honest_backup_note(): void
    {
        $section = $this->section();

        $this->assertStringContainsString('Files &amp; backups', $section);
        $this->assertStringContainsString('Import a CSV downloaded from Google Sheets, Drive, or Dropbox. Save exported reports wherever you prefer.', $section);
        $this->assertStringContainsString('CSV reports are for reading and sharing. ClassPulse does not create a complete, restorable backup yet; back up the whole database from your hosting panel (see the owner guide).', $section);
        $this->assertStringContainsString('Import roster', $section);
        $this->assertStringContainsString('Export reports', $section);
        $this->assertStringContainsString("Each student's history CSV is on that student's History page", $section);
        $this->assertStringContainsString('#icon-download', $section);
    }

    public function test_every_link_points_to_an_existing_route_and_the_exports_download_csv(): void
    {
        AcademicPeriod::create(['school_class_id' => $this->class->id, 'kind' => 'q1', 'label' => 'Q1 / Midterm', 'starts_on' => '2026-09-08', 'ends_on' => '2026-10-30']);
        AcademicPeriod::create(['school_class_id' => $this->class->id, 'kind' => 'q2', 'label' => 'Q2 / Finals', 'starts_on' => '2026-11-02', 'ends_on' => '2027-01-29']);

        $section = $this->section();
        preg_match_all('/<a [^>]*href="([^"]+)"/', $section, $m);
        $links = array_map(fn (string $href) => html_entity_decode($href), $m[1]);

        $id = $this->class->id;
        $this->assertCount(5, $links);
        $this->assertSame(url('/classes/'.$id.'/import'), $links[0]);
        $this->assertStringStartsWith(url('/export/weekly.csv?class='.$id.'&week='), $links[1]);
        $this->assertSame(url('/export/semester.csv?class='.$id), $links[2]);
        $this->assertSame(url('/export/semester.csv?class='.$id.'&period=q1'), $links[3]);
        $this->assertSame(url('/export/semester.csv?class='.$id.'&period=q2'), $links[4]);
        $this->assertStringContainsString('Semester CSV, Full Semester', $section);
        $this->assertStringContainsString('Semester CSV, Q1 / Midterm', $section);

        $this->get($links[0])->assertOk()->assertSee('Import students into HNL 2O');
        foreach (array_slice($links, 1) as $link) {
            $response = $this->get($link)->assertOk();
            $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
            $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('Alex Rivera', $response->getContent());
        }
    }

    public function test_unconfigured_quarters_are_not_offered(): void
    {
        $section = $this->section();

        $this->assertStringContainsString('Semester CSV, Full Semester', $section);
        $this->assertStringNotContainsString('period=q1', $section);
        $this->assertStringNotContainsString('period=q2', $section);
    }

    public function test_no_fake_cloud_sync_or_backup_controls(): void
    {
        $section = $this->section();

        foreach (['Connect', 'Sync', 'Synced', 'Restore', 'Back up now', 'Backup now', 'Create backup', 'Authorize', 'OAuth', 'Sign in with'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, strip_tags($section), 'Forbidden control text: '.$word);
        }
        $this->assertStringNotContainsString('<button', $section);
        $this->assertStringNotContainsString('<form', $section);
        $this->assertStringNotContainsString('<input', $section);
        $this->assertStringNotContainsString('href="https://', $section);
        $this->assertStringNotContainsString('drive.google.com', $section);
    }

    public function test_the_section_follows_calculation_rules_and_uses_the_group_pattern(): void
    {
        $html = $this->get('/roster?class='.$this->class->id)->getContent();

        $this->assertLessThan(strpos($html, 'id="files-title"'), strpos($html, 'id="rules-title"'));
        $this->assertLessThan(strpos($html, 'id="danger-title"'), strpos($html, 'id="files-title"'));
        $this->assertStringContainsString('<details class="ro-group ro-group-solo" id="files" data-ro-group>', $html);
        $this->assertStringContainsString('ui-icon-chip-blue', $this->section());
    }
}
