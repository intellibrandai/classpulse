<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Static guards for the adaptive header. The real proof is `node scripts/dev/header-sweep.mjs` (layout measured in
 * Chrome); these tests keep the break-point rules and the DOM order from being edited away unnoticed.
 */
class HeaderLayoutGuardTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return File::get(public_path('css/shell.css'));
    }

    public function test_the_measured_break_points_exist_in_shell_css(): void
    {
        $css = $this->css();

        // Single row, collapsing the lowest-priority pieces first, then the two-row grid, then phones.
        foreach ([1899, 1619, 1499, 1399, 1299, 1100, 900, 768, 599, 420, 359] as $width) {
            $this->assertStringContainsString("@media (max-width: {$width}px)", $css, "missing break point {$width}px");
        }
        $this->assertMatchesRegularExpression('/@media \(max-width: 1100px\)\s*\{\s*\.top-nav\s*\{[^}]*display:\s*grid;[^}]*grid-template-areas:\s*"left left" "tabs right"/s', $css);
        $this->assertStringContainsString('@media (max-width: 1100px) and (min-width: 600px)', $css);
        $this->assertStringContainsString('grid-template-areas: "left chips" "tabs right"', $css);
        $this->assertMatchesRegularExpression('/\.class-menu-header \.class-menu-name\s*\{[^}]*max-width:\s*120px/s', $css);
        $this->assertMatchesRegularExpression('/\.class-menu\s*\{[^}]*min-width:\s*0/s', $css);
    }

    public function test_chips_and_today_collapse_before_anything_else_and_the_plus_never_disappears(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/@media \(max-width: 1619px\)\s*\{\s*\.today-block\s*\{\s*display:\s*none/', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 1299px\)\s*\{\s*\.header-chips\s*\{\s*display:\s*none/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.class-add\s*\{[^}]*display:\s*none/', $css, 'the create-class "+" must stay visible at every width');
        foreach (['.class-menu-summary', '.theme-switch', '.btn-undo', '.avatar-btn', '.brand-cluster'] as $always) {
            $this->assertDoesNotMatchRegularExpression('/'.preg_quote($always, '/').'\s*\{[^}]*display:\s*none/', $css, "{$always} must never be hidden");
        }
    }

    public function test_the_layout_does_not_reorder_focusable_controls_with_css_order(): void
    {
        $this->assertDoesNotMatchRegularExpression('/(^|[\s;{])order:\s*-?\d/m', $this->css(), 'the header uses grid areas and DOM order, never the order property');
    }

    public function test_dom_order_is_the_reading_order_logo_class_plus_tabs_chips_theme_undo_account(): void
    {
        $this->actingAs(User::factory()->create());
        SchoolClass::factory()->create(['name' => 'HNL 2O']);

        $html = $this->get('/weekly')->assertOk()->getContent();
        $header = substr($html, (int) strpos($html, '<header class="top-nav">'), (int) strpos($html, '</header>'));
        $positions = [];
        foreach (['brand-cluster', 'class-menu-summary', 'class-add', 'screen-tabs', 'header-chips', 'theme-switch', 'btn-undo', 'avatar-btn'] as $marker) {
            $at = strpos($header, $marker);
            $this->assertNotFalse($at, "header is missing {$marker}");
            $positions[$marker] = $at;
        }

        $sorted = $positions;
        asort($sorted);
        $this->assertSame(array_keys($positions), array_keys($sorted), 'header DOM order changed');
        $this->assertSame(4, substr_count($header, 'class="screen-tab-btn"'));
    }

    public function test_tabs_keep_their_names_for_icon_only_widths(): void
    {
        $this->actingAs(User::factory()->create());
        SchoolClass::factory()->create();

        $html = $this->get('/daily')->assertOk()->getContent();

        foreach (['Daily Tracker', 'Weekly Matrix', 'Semester Analytics', 'Class Roster &amp; Settings'] as $name) {
            $this->assertStringContainsString('aria-label="'.$name.'"', $html);
            $this->assertStringContainsString('title="'.$name.'"', $html);
        }
    }
}
