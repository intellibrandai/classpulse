<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Markup and CSS guards for the defects found by scripts/dev/keyboard-audit.mjs (docs/keyboard-audit.md).
 * The behaviour itself (real key events in headless Chrome) is a manual dev check; these tests only keep the
 * markup and the key rules from regressing.
 */
class KeyboardFocusTest extends TestCase
{
    use RefreshDatabase;

    private function css(string $file): string
    {
        return File::get(public_path('css/'.$file));
    }

    private function js(string $file): string
    {
        return File::get(public_path('js/'.$file));
    }

    public function test_layout_loads_the_focus_scroll_helper_and_keeps_the_skip_link(): void
    {
        $html = (string) $this->blade('<x-layouts.app title="Probe" active="daily">Body</x-layouts.app>');

        $this->assertMatchesRegularExpression('/<script src="[^"]*js\/focus-scroll\.js" defer><\/script>/', $html);
        $this->assertStringContainsString('<a class="skip-link" href="#main">', $html);
    }

    public function test_skip_link_sits_above_the_sticky_header_and_focus_scrolls_clear_of_header_and_footer(): void
    {
        $app = $this->css('app.css');

        $this->assertMatchesRegularExpression('/\.skip-link\s*\{[^}]*z-index:\s*200/s', $app, 'the skip link must paint above .top-nav (z-index 100)');
        $this->assertMatchesRegularExpression('/html\s*\{\s*scroll-padding-top:\s*80px;\s*scroll-padding-bottom:\s*56px/', $app);
        $this->assertStringContainsString('z-index: 100;', $this->css('shell.css'));
        $this->assertMatchesRegularExpression('/\.wm-scroll\s*\{[^}]*scroll-padding-block:/s', $this->css('weekly.css'));
        $this->assertMatchesRegularExpression('/\.sem-scroll\s*\{[^}]*scroll-padding-block:/s', $this->css('semester.css'));
    }

    public function test_scrolling_strips_leave_room_for_the_focus_ring(): void
    {
        $this->assertMatchesRegularExpression('/\.filter-chips\s*\{[^}]*padding:\s*6px 6px 8px;\s*margin:\s*-6px/s', $this->css('daily.css'));
        $this->assertMatchesRegularExpression('/\.ro-settings \.ro-group-summary,[^{]*\{\s*scroll-margin-bottom:\s*84px/s', $this->css('roster.css'), 'settings fields stay clear of the sticky save bar');
        $this->assertMatchesRegularExpression('/\.ro-classes\s*\{[^}]*padding:\s*6px 6px 10px;\s*margin:\s*-6px -6px 10px/s', $this->css('roster.css'));
    }

    public function test_student_card_name_button_reports_its_selected_state(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 10:00', 'America/Toronto'));
        $this->actingAs(User::factory()->create());
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O']);
        Student::factory()->create(['school_class_id' => $class->id, 'display_name' => 'Alex Rivera']);

        $html = $this->get('/daily?class='.$class->id)->assertOk()->getContent();
        CarbonImmutable::setTestNow();

        $this->assertMatchesRegularExpression('/<button type="button" class="student-name"[^>]*aria-pressed="false"/', $html);
        $this->assertStringContainsString("name.setAttribute('aria-pressed'", $this->js('daily.js'));
    }

    public function test_semester_actions_come_before_the_period_row_so_tab_order_matches_the_layout(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 10:00', 'America/Toronto'));
        $this->actingAs(User::factory()->create());
        $class = SchoolClass::factory()->create(['name' => 'HNL 2O', 'semester_start' => '2026-09-08', 'semester_end' => '2027-01-29']);
        Student::factory()->create(['school_class_id' => $class->id, 'display_name' => 'Alex Rivera']);

        $html = $this->get('/semester?class='.$class->id)->assertOk()->getContent();
        CarbonImmutable::setTestNow();

        $this->assertLessThan(strpos($html, 'class="sem-periods"'), strpos($html, 'class="sem-actions"'));
        $this->assertMatchesRegularExpression('/\.sem-periods\s*\{[^}]*order:\s*3/s', $this->css('semester.css'), 'the CSS order keeps the period row last visually');
    }

    public function test_sheets_trap_focus_and_controls_that_disable_themselves_hand_the_focus_on(): void
    {
        $daily = $this->js('daily.js');
        $semester = $this->js('semester.js');
        $dialogs = $this->js('dialogs.js');

        $this->assertStringContainsString('function trapTab', $dialogs);
        $this->assertStringContainsString('ClassPulseDialogs.trapTab(panel, event)', $daily);
        $this->assertStringContainsString('dialogs.trapTab(inspector, event)', $semester);
        $this->assertStringContainsString('function rescueFocus', $daily);
        $this->assertStringContainsString("dayMenu.querySelector('summary')", $daily, 'day-menu dialogs return focus to the menu button');
        $this->assertStringContainsString('toggleButton.focus()', $semester, 'Escape closes the inspector sheet and returns focus to its toggle');
        $this->assertStringContainsString('input.focus()', $semester, 'Reset / Retry hand the focus to the draft when they switch themselves off');
        $this->assertStringContainsString('card.focus()', $this->js('roster.js'), 'closing the hidden create form hands the focus to its card');
        $this->assertStringContainsString('focusFlash', $this->js('shell.js'));
    }

    public function test_keyboard_audit_tool_is_dev_only_and_uses_real_key_events(): void
    {
        $exclude = File::get(base_path('scripts/release-exclude.txt'));
        $this->assertStringContainsString('/scripts/', $exclude);
        $this->assertStringContainsString('/docs/', $exclude);

        foreach (['keyboard-audit.mjs', 'lib/kb-flows.mjs', 'lib/kb-page.mjs', 'lib/pdf-edge.mjs'] as $file) {
            $this->assertFileExists(base_path('scripts/dev/'.$file));
            $source = File::get(base_path('scripts/dev/'.$file));
            $this->assertStringNotContainsString('require(', $source);
            $this->assertStringNotContainsString("from 'puppeteer", $source);
        }
        $cdp = File::get(base_path('scripts/dev/lib/cdp.mjs'));
        $this->assertStringContainsString('Input.dispatchKeyEvent', $cdp);
        $this->assertStringNotContainsString('nativeVirtualKeyCode', $cdp, 'a Windows key code sent as a macOS native code floods the page with bogus keydown events');
        $this->assertStringNotContainsString('.click()', File::get(base_path('scripts/dev/lib/kb-flows.mjs')), 'flows press keys; they never call element.click()');
    }
}
