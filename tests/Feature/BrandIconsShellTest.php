<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Initials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BrandIconsShellTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> label => rendered html of every layout */
    private function layouts(): array
    {
        $login = $this->get('/login')->getContent();
        $this->actingAs(User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.test']));
        $class = SchoolClass::factory()->create(['name' => 'HNL 20', 'period_label' => 'Period 2']);

        return [
            'app' => (string) $this->blade('<x-layouts.app title="Probe" :classes="$classes" :current-class="$class">Body</x-layouts.app>', ['classes' => collect([$class]), 'class' => $class]),
            'error' => $this->get('/no-such-page')->getContent(),
            'login' => $login,
        ];
    }

    public function test_every_layout_has_the_icon_links_the_official_mark_and_no_old_brand_tile(): void
    {
        foreach ($this->layouts() as $name => $html) {
            $this->assertStringContainsString('<link rel="icon" href="', $html, "{$name}: favicon.ico link");
            $this->assertMatchesRegularExpression('#<link rel="icon" href="[^"]*/favicon\.ico"#', $html, $name);
            $this->assertStringContainsString('brand/classpulse-favicon.svg', $html, "{$name}: svg icon");
            $this->assertStringContainsString('rel="apple-touch-icon"', $html, "{$name}: apple touch icon");
            $this->assertStringContainsString('brand/classpulse-mark.svg', $html, "{$name}: dark mark");
            $this->assertStringContainsString('brand/classpulse-mark-light.svg', $html, "{$name}: light mark");
            $this->assertStringContainsString('ClassPulse', $html);
            $this->assertStringNotContainsString('brand-icon', $html, "{$name}: old brand tile");
            $this->assertStringNotContainsString('⚡', $html, "{$name}: lightning tile");
            $this->assertStringContainsString('css/glass.css', $html, "{$name}: glass kit");
        }
    }

    public function test_brand_assets_exist_on_disk(): void
    {
        foreach (['favicon.ico', 'brand/classpulse-mark.svg', 'brand/classpulse-mark-light.svg', 'brand/classpulse-favicon.svg', 'brand/apple-touch-icon.png'] as $file) {
            $this->assertFileExists(public_path($file));
        }
    }

    public function test_the_mark_is_swapped_by_css_for_both_themes(): void
    {
        $css = File::get(public_path('css/shell.css'));

        $this->assertMatchesRegularExpression('/\[data-theme="dark"\] \.brand-mark \.brand-mark-dark\s*\{\s*display:\s*block/', $css);
        $this->assertMatchesRegularExpression('/:root:not\(\[data-theme="light"\]\) \.brand-mark \.brand-mark-dark\s*\{\s*display:\s*block/', $css);
    }

    public function test_no_emoji_characters_in_blade_views(): void
    {
        $emoji = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{FE0F}\x{200D}]|&(?:larr|rarr|uarr|darr|harr|check|cross);/u';

        foreach (File::allFiles(resource_path('views')) as $file) {
            $this->assertSame(0, preg_match($emoji, File::get($file->getPathname()), $m), "{$file->getRelativePathname()} contains emoji or an arrow glyph: ".($m[0] ?? ''));
        }
    }

    public function test_every_icon_used_in_views_exists_in_the_sprite_and_the_sprite_has_the_required_set(): void
    {
        $sprite = File::get(resource_path('views/components/icons.blade.php'));
        preg_match_all('/<symbol id="icon-([a-z0-9-]+)" viewBox="0 0 24 24">/', $sprite, $defined);
        $defined = $defined[1];

        $required = ['calendar', 'chevron-left', 'chevron-right', 'chevron-down', 'plus', 'user-plus', 'users', 'printer', 'search', 'undo', 'sun', 'moon', 'user',
            'clipboard-list', 'grid', 'trending-up', 'chart-line', 'sliders', 'upload', 'file-input', 'download', 'file-text', 'copy', 'message-square', 'pencil',
            'archive', 'archive-restore', 'alert-triangle', 'lock', 'check-circle', 'shield-check', 'activity', 'zap', 'pointer', 'bar-chart', 'filter', 'x',
            'more-horizontal', 'info', 'arrow-up-down', 'log-out', 'refresh-cw', 'eye', 'list'];
        $this->assertSame([], array_values(array_diff($required, $defined)), 'sprite is missing icons');
        $this->assertSame(array_values(array_unique($defined)), $defined, 'duplicate symbol ids');

        $used = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('/<x-icon\s+name="([a-z0-9-]+)"/', File::get($file->getPathname()), $m);
            $used = array_merge($used, $m[1]);
        }
        $this->assertNotEmpty($used);
        $this->assertSame([], array_values(array_diff(array_unique($used), $defined)), 'views use icons that are not in the sprite');
    }

    public function test_icon_component_renders_a_decorative_stroke_svg(): void
    {
        $html = (string) $this->blade('<x-icon name="calendar" class="icon-18" />');

        $this->assertStringContainsString('viewBox="0 0 24 24"', $html);
        $this->assertStringContainsString('stroke-width="1.75"', $html);
        $this->assertStringContainsString('stroke="currentColor"', $html);
        $this->assertStringContainsString('stroke-linecap="round"', $html);
        $this->assertStringContainsString('fill="none"', $html);
        $this->assertStringContainsString('class="icon icon-18"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('href="#icon-calendar"', $html);
    }

    public function test_header_has_the_spec_blocks_chips_today_undo_theme_switch_and_account_menu(): void
    {
        $html = $this->layouts()['app'];
        preg_match('/<header class="top-nav">.*?<\/header>/s', $html, $m);
        $header = $m[0] ?? '';

        $this->assertNotSame('', $header);
        $this->assertSame(0, substr_count($header, '<select'));
        $this->assertSame(1, substr_count($header, '<details class="class-menu'));
        $this->assertStringContainsString('<span class="class-menu-period">· Period 2</span>', $header);
        $this->assertStringContainsString('Active class: HNL 20. Choose a class', $header);
        $this->assertStringContainsString('href="'.url('/roster?create=1').'"', $header);
        $this->assertStringContainsString('Enrolled', $header);
        $this->assertStringContainsString('Present', $header);
        $this->assertStringContainsString('Absent', $header);
        $this->assertStringContainsString('data-theme-set="light"', $header);
        $this->assertStringContainsString('data-theme-set="dark"', $header);
        $this->assertStringContainsString('title="Nothing to undo here"', $header);
        $this->assertMatchesRegularExpression('/<button type="button" class="btn-undo" disabled/', $header);
        $this->assertStringContainsString('Today', $header);
        $this->assertStringContainsString('data-account-toggle', $header);
        $this->assertStringContainsString('aria-expanded="false"', $header);
        $this->assertStringContainsString('>JD<', $header);
        $this->assertStringContainsString('jane@example.test', $header);
        $this->assertStringContainsString('action="'.url('/logout').'"', $header);
        $this->assertStringContainsString('Log out', $header);
        foreach (['Daily Tracker', 'Weekly Matrix', 'Semester Analytics', 'Class Roster &amp; Settings'] as $tab) {
            $this->assertStringContainsString($tab, $header);
        }
        $this->assertStringContainsString('css/print.css" media="print"', $html);
    }

    public function test_header_hides_chips_without_a_class_but_keeps_the_create_link(): void
    {
        $this->actingAs(User::factory()->create());
        $html = (string) $this->blade('<x-layouts.app title="Probe">Body</x-layouts.app>');

        $this->assertStringNotContainsString('header-chips', $html);
        $this->assertStringContainsString('/roster?create=1', $html);
    }

    public function test_roster_opens_the_create_form_for_the_header_plus_link(): void
    {
        $this->actingAs(User::factory()->create());
        SchoolClass::factory()->create(['name' => 'HNL 20']);

        $this->get('/roster?create=1')->assertOk()->assertSee('id="create-class"', false);
        $this->assertMatchesRegularExpression('/<details class="ro-create[^"]*" id="create-class"\s+open\s*>/', $this->get('/roster?create=1')->getContent());
        $this->assertDoesNotMatchRegularExpression('/id="create-class"\s+open/', $this->get('/roster')->getContent());
    }

    public function test_initials_use_the_name_then_the_email(): void
    {
        $this->assertSame('JD', Initials::of('Jane Doe', 'x@y.z'));
        $this->assertSame('M', Initials::of('madonna', null));
        $this->assertSame('AB', Initials::of('Ann Marie Brown', null));
        $this->assertSame('T', Initials::of('', 'teacher@classpulse.test'));
        $this->assertSame('?', Initials::of(null, null));
    }

    public function test_print_header_renders_logo_class_label_and_timestamp_when_the_page_opts_in(): void
    {
        $this->actingAs(User::factory()->create());
        $class = SchoolClass::factory()->create(['name' => 'HNL 20', 'title' => 'Nutrition & Health']);

        $html = (string) $this->blade('<x-layouts.app title="Probe" :current-class="$class" print-title="Weekly Sheet" print-label="Week 8: Oct 19 - Oct 23">Body</x-layouts.app>', ['class' => $class]);

        $this->assertStringContainsString('class="print-header"', $html);
        $this->assertStringContainsString('classpulse-mark-light.svg', $html);
        $this->assertStringContainsString('ClassPulse · Student Participation Tracker', $html);
        $this->assertStringContainsString('HNL 20', $html);
        $this->assertStringContainsString('Nutrition &amp; Health', $html);
        $this->assertStringContainsString('Weekly Sheet', $html);
        $this->assertStringContainsString('Week 8: Oct 19 - Oct 23', $html);
        $this->assertMatchesRegularExpression('/Printed on <time data-printed-at/', $html);

        $plain = (string) $this->blade('<x-layouts.app title="Probe">Body</x-layouts.app>');
        $this->assertStringNotContainsString('class="print-header"', $plain);
    }

    public function test_print_stylesheet_hides_chrome_resets_colours_and_keeps_rows_whole(): void
    {
        $css = File::get(public_path('css/print.css'));

        $this->assertStringContainsString('@page', $css);
        $this->assertMatchesRegularExpression('/@page\s*\{[^}]*margin:\s*14mm 12mm/s', $css);
        $this->assertSame(1, preg_match('/\.skip-link,(.*?)\{\s*display:\s*none\s*!important;\s*\}/s', $css, $hide), 'hide rule not found');
        foreach (['.top-nav', '.app-footer', 'button', '.action-btn', '.toolbar', '.no-print'] as $hidden) {
            $this->assertStringContainsString($hidden, $hide[1], "{$hidden} is not hidden for print");
        }
        $this->assertMatchesRegularExpression('/background:\s*white\s*!important/', $css);
        $this->assertMatchesRegularExpression('/color:\s*black\s*!important/', $css);
        $this->assertMatchesRegularExpression('/tr,[^{]*\{[^}]*break-inside:\s*avoid/s', $css);
        $this->assertMatchesRegularExpression('/thead\s*\{\s*display:\s*table-header-group/', $css);
        $this->assertMatchesRegularExpression('/\.print-header\s*\{[^}]*display:\s*flex\s*!important/s', $css);
        $this->assertMatchesRegularExpression('/backdrop-filter:\s*none\s*!important/', $css);
    }

    public function test_glass_stylesheet_has_the_fallbacks_and_the_kit_classes(): void
    {
        $css = File::get(public_path('css/glass.css'));

        $this->assertStringContainsString('@supports not ((backdrop-filter: blur(1px))', $css);
        $this->assertStringContainsString('prefers-reduced-transparency: reduce', $css);
        $this->assertStringContainsString('forced-colors: active', $css);
        $this->assertStringContainsString('backdrop-filter: var(--glass-filter)', $css);
        foreach (['.glass', '.ui-panel', '.ui-kpi', '.ui-icon-chip', '.ui-badge-present', '.ui-badge-absent', '.ui-badge-zero', '.ui-badge-none', '.ui-badge-info',
            '.ui-segmented', '.ui-chip', '.ui-search', '.ui-kbd', '.ui-btn-primary', '.ui-popover', '.ui-table', '.ui-progress', '.icon-16', '.icon-18', '.icon-20', '.icon-24'] as $class) {
            $this->assertStringContainsString($class, $css, "glass.css is missing {$class}");
        }

        $tokens = File::get(public_path('css/tokens.css'));
        foreach (['--glow-primary', '--glow-success', '--glow-danger', '--glass-bg', '--glass-border', '--border-violet', '--border-blue', '--border-green', '--shadow-glass', '--bg-glow'] as $token) {
            $this->assertStringContainsString($token.':', $tokens, "tokens.css is missing {$token}");
        }
    }
}
