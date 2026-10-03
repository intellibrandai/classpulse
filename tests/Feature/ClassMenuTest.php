<?php

namespace Tests\Feature;

use App\Models\ParticipationEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The class switcher is a <details>/<summary> disclosure (components/class-menu.blade.php), not a native <select>:
 * the whole pill opens it and every class is a real link. The real-mouse proof is scripts/dev/header-sweep.mjs and the
 * keyboard flow in scripts/dev/keyboard-audit.mjs; these tests pin the markup and the per-class data separation.
 */
class ClassMenuTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $alpha;

    private SchoolClass $beta;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 10:00', 'America/Toronto'));
        $this->actingAs(User::factory()->create());
        $this->alpha = SchoolClass::factory()->create(['name' => 'AAA 1A', 'period_label' => 'Period 1', 'title' => 'Alpha Title']);
        $this->beta = SchoolClass::factory()->create(['name' => 'BBB 2B', 'period_label' => null, 'title' => null, 'subject_description' => 'Beta Subject']);
        foreach (['Alphonse Aardvark', 'Alma Anders'] as $name) {
            $this->addStudent($this->alpha, $name);
        }
        $this->addStudent($this->beta, 'Bertrand Bellweather');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function addStudent(SchoolClass $class, string $name): Student
    {
        $student = Student::factory()->create(['school_class_id' => $class->id, 'display_name' => $name]);
        ParticipationEntry::factory()->create([
            'school_class_id' => $class->id,
            'student_id' => $student->id,
            'work_date' => '2026-10-21',
            'status' => 'present',
            'points' => 1,
        ]);

        return $student;
    }

    private function header(string $html): string
    {
        preg_match('/<header class="top-nav">.*?<\/header>/s', $html, $m);
        $this->assertNotEmpty($m, 'header not found');

        return $m[0];
    }

    /** @return array<string, array{string}> */
    public static function screens(): array
    {
        return ['daily' => ['/daily'], 'weekly' => ['/weekly'], 'semester' => ['/semester'], 'roster' => ['/roster']];
    }

    #[DataProvider('screens')]
    public function test_header_class_menu_is_a_details_disclosure_with_real_links(string $path): void
    {
        $html = $this->get($path.'?class='.$this->beta->id)->assertOk()->getContent();
        $header = $this->header($html);

        $this->assertStringNotContainsString('<select', $header);
        $this->assertStringNotContainsString('id="class-select"', $header);
        $this->assertStringNotContainsString('data-class-form', $header);
        $this->assertStringNotContainsString('Show selected class', $header);
        $this->assertSame(1, substr_count($header, '<details class="class-menu class-menu-header"'));
        $this->assertMatchesRegularExpression('/<summary class="class-menu-summary" aria-haspopup="true" aria-expanded="false" aria-label="Active class: BBB 2B\. Choose a class">/', $header);

        // The whole pill (eyebrow, dot, name, chevron) lives inside the summary.
        preg_match('/<summary.*?<\/summary>/s', $header, $summary);
        foreach (['pill-eyebrow', 'class="dot"', 'class-menu-name', 'class-menu-caret'] as $part) {
            $this->assertStringContainsString($part, $summary[0], "{$part} must be inside the summary");
        }

        // One real link per class, same destination the old form used; only the current one carries aria-current.
        $base = url($path);
        $this->assertStringContainsString('href="'.$base.'?class='.$this->alpha->id.'"', $header);
        $this->assertStringContainsString('href="'.$base.'?class='.$this->beta->id.'"', $header);
        $this->assertSame(2, preg_match_all('/<a class="class-menu-item[^"]*" href="[^"]*\?class=\d+"/', $header));
        $this->assertSame(1, substr_count($header, 'aria-current="true"'));
        $this->assertMatchesRegularExpression('/class-menu-item\s+is-current\s*"\s+href="[^"]*\?class='.$this->beta->id.'"\s+aria-current="true"/', $header);
        $this->assertStringContainsString('Period 1 · Alpha Title · 2 students', $header);
        $this->assertStringContainsString('Beta Subject · 1 student', $header);
        $this->assertStringContainsString('(current class)', $header);
        $this->assertStringContainsString('class-menu-check', $header);

        // Nothing else changed: the "+" link stays, in DOM order right after the menu.
        $this->assertGreaterThan(strpos($header, '</details>'), strpos($header, 'class-add'));
    }

    public function test_semester_course_chip_uses_the_same_component(): void
    {
        $html = $this->get('/semester?class='.$this->alpha->id)->assertOk()->getContent();
        preg_match('/<div class="sem-course">.*?<\/details>/s', $html, $m);

        $this->assertNotEmpty($m);
        $this->assertStringContainsString('class-menu-chip', $m[0]);
        $this->assertStringContainsString('aria-label="Current course: AAA 1A', $m[0]);
        $this->assertStringContainsString('AAA 1A • Alpha Title', $m[0]);
        $this->assertStringContainsString('href="'.url('/semester').'?class='.$this->beta->id.'"', $m[0]);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="class"/', $html);
        $this->assertStringNotContainsString('data-class-form', $html);
    }

    public function test_no_class_switching_control_is_a_native_select_anywhere(): void
    {
        foreach (['/daily', '/weekly', '/semester', '/roster'] as $path) {
            $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="class"/', $this->get($path.'?class='.$this->alpha->id)->getContent(), $path);
        }
    }

    public function test_switching_class_through_the_links_shows_only_that_classes_data_on_every_screen(): void
    {
        $screens = [
            '/daily' => ['Alphonse Aardvark', 'Alma Anders', 'Bertrand Bellweather'],
            '/weekly' => ['Alphonse Aardvark', 'Alma Anders', 'Bertrand Bellweather'],
            '/semester' => ['Alphonse Aardvark', 'Alma Anders', 'Bertrand Bellweather'],
            '/roster' => ['Alphonse Aardvark', 'Alma Anders', 'Bertrand Bellweather'],
        ];

        foreach ($screens as $path => [$a1, $a2, $b1]) {
            // Follow the exact href the menu renders for each class.
            $alphaHtml = $this->get($this->hrefFor($path, $this->alpha))->assertOk()->getContent();
            $this->assertStringContainsString($a1, $alphaHtml, "{$path} alpha");
            $this->assertStringContainsString($a2, $alphaHtml, "{$path} alpha");
            $this->assertStringNotContainsString($b1, $alphaHtml, "{$path} alpha leaks beta");

            $betaHtml = $this->get($this->hrefFor($path, $this->beta))->assertOk()->getContent();
            $this->assertStringContainsString($b1, $betaHtml, "{$path} beta");
            $this->assertStringNotContainsString($a1, $betaHtml, "{$path} beta leaks alpha");
            $this->assertStringNotContainsString($a2, $betaHtml, "{$path} beta leaks alpha");
        }
    }

    public function test_header_chips_follow_the_selected_class(): void
    {
        $alpha = $this->header($this->get('/daily?class='.$this->alpha->id)->getContent());
        $beta = $this->header($this->get('/daily?class='.$this->beta->id)->getContent());

        $this->assertMatchesRegularExpression('/<strong>2<\/strong> Enrolled/', $alpha);
        $this->assertMatchesRegularExpression('/<strong>1<\/strong> Enrolled/', $beta);
    }

    public function test_a_class_id_that_does_not_exist_falls_back_exactly_as_before(): void
    {
        foreach (['/daily', '/weekly', '/semester', '/roster'] as $path) {
            // The page does not 404: it keeps the current class (session) or the first class by name.
            $html = $this->get($path.'?class='.$this->beta->id)->assertOk()->getContent();
            $this->assertStringContainsString('Active class: BBB 2B', $html);
            $fallback = $this->get($path.'?class=999999')->assertOk()->getContent();
            $this->assertStringContainsString('Active class: BBB 2B', $fallback, "{$path} keeps the session class");
            $this->assertStringContainsString('Bertrand Bellweather', $fallback);
        }
    }

    public function test_class_menu_css_and_js_have_no_dead_select_paths(): void
    {
        $this->assertStringNotContainsString('data-class-form', File::get(public_path('js/shell.js')));
        foreach (['shell.css', 'semester.css'] as $file) {
            $css = File::get(public_path('css/'.$file));
            $this->assertDoesNotMatchRegularExpression('/class-pill-selector|sem-course-select|\.class-form/', $css, $file);
        }
        $shell = File::get(public_path('css/shell.css'));
        $this->assertMatchesRegularExpression('/\.class-menu-panel\s*\{[^}]*z-index:\s*120;[^}]*width:\s*min\(360px, calc\(100vw - 24px\)\);[^}]*max-height:\s*min\(60vh, 420px\);[^}]*overflow-y:\s*auto/s', $shell);
        $this->assertMatchesRegularExpression('/\.class-menu-summary\s*\{[^}]*min-height:\s*38px/s', $shell);
        $this->assertStringContainsString('list-style: none', $shell);
    }

    public function test_shell_js_drives_the_menu_with_keys_focus_and_outside_clicks(): void
    {
        $js = File::get(public_path('js/shell.js'));

        foreach (['[data-class-menu]', "'ArrowDown'", "'ArrowUp'", "'Home'", "'End'", "'Escape'", 'aria-expanded', "'toggle'", "'focusout'", "'click'", 'classpulse:menu-open'] as $needle) {
            $this->assertStringContainsString($needle, $js, "shell.js must handle {$needle}");
        }
        $this->assertDoesNotMatchRegularExpression('/^\s*(import|export)\s/m', $js, 'classic script: no import/export');
        $this->assertStringContainsString('classpulse:menu-open', File::get(public_path('js/daily.js')), 'the Daily menu closes when a header menu opens');
    }

    private function hrefFor(string $path, SchoolClass $class): string
    {
        $html = $this->get($path.'?class='.$class->id)->getContent();
        preg_match('/<a class="class-menu-item[^"]*" href="([^"]+)"[^>]*>\s*<span class="class-menu-item-text">\s*<span class="class-menu-item-code">'.preg_quote($class->name, '/').'</', $html, $m);
        $this->assertNotEmpty($m, "menu link for {$class->name} on {$path}");

        return html_entity_decode($m[1]);
    }
}
