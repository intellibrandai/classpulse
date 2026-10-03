<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Support\CurrentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LayoutShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_renders_nav_theme_toggle_and_skip_link(): void
    {
        $classes = collect([SchoolClass::factory()->create(['name' => 'HNL 10'])]);
        $current = $classes->first();

        $view = $this->blade(
            '<x-layouts.app title="Probe" active="daily" :classes="$classes" :current-class="$current">Body</x-layouts.app>',
            ['classes' => $classes, 'current' => $current],
        );

        $view->assertSee('Daily Tracker');
        $view->assertSee('Weekly Matrix');
        $view->assertSee('Semester Analytics');
        $view->assertSee('Class Roster &amp; Settings', false);
        $view->assertSee('data-theme-set="light"', false);
        $view->assertSee('data-theme-set="dark"', false);
        $view->assertSee('href="#main"', false);
        $view->assertSee('<main id="main"', false);
        $view->assertSee('aria-current="page"', false);
        $view->assertSee('Student Participation Tracker');
        $view->assertSee('<details class="class-menu class-menu-header" data-class-menu>', false);
        $view->assertSee('Active class: HNL 10. Choose a class', false);
        $view->assertDontSee('<select', false);
        $view->assertDontSee('>Switch<', false);
        $view->assertSee('HNL 10');
        $view->assertSee('class="app-footer"', false);
    }

    public function test_theme_init_is_blocking_and_loads_before_stylesheets(): void
    {
        $html = (string) $this->blade('<x-layouts.app title="Probe" active="weekly">Body</x-layouts.app>');

        preg_match('/<script src="[^"]*js\/theme-init\.js"([^>]*)><\/script>/', $html, $init, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($init);
        $this->assertStringNotContainsString('defer', $init[1][0]);
        $this->assertStringNotContainsString('async', $init[1][0]);

        $initPos = $init[0][1];
        $this->assertLessThan(strpos($html, 'css/fonts.css'), $initPos);
        $this->assertLessThan(strpos($html, 'css/tokens.css'), $initPos);
        $this->assertLessThan(strpos($html, 'css/app.css'), $initPos);
        $this->assertLessThan(strpos($html, '</head>'), $initPos);
        $this->assertMatchesRegularExpression('/<script src="[^"]*js\/theme\.js" defer><\/script>/', $html);
        $this->assertMatchesRegularExpression('/<script src="[^"]*js\/shell\.js" defer><\/script>/', $html);
    }

    public function test_current_class_resolution(): void
    {
        $this->assertNull(CurrentClass::resolve(null));

        $a = SchoolClass::factory()->create(['name' => 'HNL 20']);
        $b = SchoolClass::factory()->create(['name' => 'HNL 30']);
        $c = SchoolClass::factory()->create(['name' => 'HNL 10']);

        $this->assertTrue(CurrentClass::resolve($b->id)->is($b));
        $this->assertSame($b->id, session(CurrentClass::SESSION_KEY));

        $this->assertTrue(CurrentClass::resolve(null)->is($b));

        $this->assertTrue(CurrentClass::resolve(999999)->is($b));

        $b->delete();
        $this->assertTrue(CurrentClass::resolve(999999)->is($c));
        $this->assertTrue(CurrentClass::resolve(null)->is($c));
        $this->assertTrue(CurrentClass::resolve($a->id)->is($a));
    }

    public function test_views_contain_no_inline_script_or_style(): void
    {
        $patterns = [
            'style=' => '/style=/i',
            '<style' => '/<style/i',
            'inline <script>' => '/<script(?![^>]*\bsrc=)/i',
            'on*= attribute' => '/\son[a-z]+=/i',
            '{!!' => '/\{!!/',
            '@vite' => '/@vite/',
        ];

        $files = File::allFiles(resource_path('views'));
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $contents = File::get($file->getPathname());
            foreach ($patterns as $label => $regex) {
                $this->assertSame(0, preg_match($regex, $contents), "{$file->getRelativePathname()} contains {$label}");
            }
        }
    }

    public function test_tokens_css_contains_both_stitch_theme_sets_and_the_legacy_names(): void
    {
        $css = strtolower(File::get(public_path('css/tokens.css')));
        $stitch = [
            // dark
            '#0b1326', '#0f172a', '#162035', '#131b2e', '#111a2f', '#23314d', '#6366f1', '#f8fafc', '#94a3b8', '#1e293b', '#1e2540', '#818cf8',
            // light
            '#f4f6fb', '#ffffff', '#f8faff', '#e2e8f0', '#4f46e5', '#0f172a', '#475569', '#f1f5f9', '#e0e7ff', '#4338ca',
            // shared status colours
            '#10b981', '#ef4444', '#f59e0b',
        ];
        // Muted text values are covered by tests/js/contrast.test.js (WCAG ratios), not by exact hex values.
        foreach ($stitch as $hex) {
            $this->assertStringContainsString($hex, $css, "tokens.css is missing {$hex}");
        }
        foreach (['[data-theme="dark"]', 'prefers-color-scheme: dark', '--font-main', '--font-mono', 'jetbrains mono', '--bg:', '--surface:', '--fg:', '--muted:', '--primary:', '--success:', '--destructive:', '--heat-high:'] as $needle) {
            $this->assertStringContainsString(strtolower($needle), $css, "tokens.css is missing {$needle}");
        }
    }

    public function test_fonts_are_self_hosted_and_referenced_only_locally(): void
    {
        $css = File::get(public_path('css/fonts.css'));

        $this->assertStringContainsString('Plus Jakarta Sans', $css);
        $this->assertStringContainsString('JetBrains Mono', $css);
        $this->assertDoesNotMatchRegularExpression('#(https?:)?//#', $css);
        preg_match_all('#url\("\.\./fonts/([^"]+)"\)#', $css, $files);
        $this->assertNotEmpty($files[1]);
        foreach ($files[1] as $file) {
            $this->assertFileExists(public_path('fonts/'.$file));
        }
    }

    public function test_stylesheets_keep_colours_in_tokens_only(): void
    {
        foreach (['app.css', 'shell.css', 'daily.css', 'screens.css', 'glass.css', 'print.css'] as $file) {
            $css = File::get(public_path('css/'.$file));
            $this->assertSame(0, preg_match('/#[0-9a-fA-F]{3,8}\b/', $css), "{$file} contains a raw hex colour");
            $this->assertSame(0, preg_match('/\brgba?\(/', $css), "{$file} contains a raw rgb colour");
        }
    }

    public function test_error_pages_use_the_app_look(): void
    {
        $response = $this->get('/no-such-page');

        $response->assertNotFound();
        $response->assertSee('css/screens.css', false);
        $response->assertSee('Page not found');
        $response->assertSee('Back to the Daily Tracker');
    }

    public function test_table_scroll_containers_contain_their_hidden_text(): void
    {
        // Visually-hidden cell text is position:absolute; the scroller must be its containing block or it widens the page.
        $css = File::get(public_path('css/screens.css'));

        $this->assertMatchesRegularExpression('/\.table-container\s*\{[^}]*overflow-x:\s*auto;[^}]*position:\s*relative;/s', $css);
    }
}
