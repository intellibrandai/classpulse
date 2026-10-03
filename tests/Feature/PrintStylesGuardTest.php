<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Guards the print rules proven with scripts/dev/print-pdfs.mjs (Letter PDFs reviewed page by page).
 * It only checks that the key rules still exist; the PDFs themselves are a manual dev check.
 */
class PrintStylesGuardTest extends TestCase
{
    private function css(string $file): string
    {
        return File::get(public_path('css/'.$file));
    }

    public function test_print_base_hides_chrome_and_repeats_table_headers(): void
    {
        $css = $this->css('print.css');

        $this->assertSame(1, preg_match('/\.skip-link,(.*?)\{\s*display:\s*none\s*!important;\s*\}/s', $css, $hide));
        foreach (['.top-nav', '.app-footer', 'dialog', 'button', '.ui-btn', '.toolbar', '.no-print', '.panel-toggle'] as $selector) {
            $this->assertStringContainsString($selector, $hide[1], "{$selector} must be hidden in print");
        }
        $this->assertMatchesRegularExpression('/\.ui-avatar\s*\{\s*display:\s*none\s*!important/', $css);
        $this->assertMatchesRegularExpression('/thead\s*\{\s*display:\s*table-header-group/', $css);
        $this->assertMatchesRegularExpression('/tr,[^{]*\{[^}]*break-inside:\s*avoid/s', $css);
        $this->assertMatchesRegularExpression('/box-shadow:\s*none\s*!important/', $css);
        $this->assertMatchesRegularExpression('/backdrop-filter:\s*none\s*!important/', $css);
        $this->assertStringContainsString('size: auto', $css);
    }

    public function test_weekly_print_prints_totals_once_and_keeps_rows_compact(): void
    {
        $css = $this->css('weekly.css');

        $this->assertMatchesRegularExpression('/@media print\s*\{.*\.wm-table tfoot\s*\{\s*display:\s*table-row-group/s', $css);
        $this->assertMatchesRegularExpression('/@media print\s*\{.*\.wm-name\s*\{\s*min-height:\s*0/s', $css);
        $this->assertMatchesRegularExpression('/@media print\s*\{.*\.wm-scroll\.glass\s*\{\s*border:\s*0\s*!important;\s*border-radius:\s*0\s*!important/s', $css, 'no rounded second frame around the printed matrix');
        $this->assertMatchesRegularExpression('/@media print\s*\{.*\.wm-table\s*\{[^}]*width:\s*calc\(100% - 1pt\)/s', $css, 'the matrix keeps a 1pt inset so its right border is not cut to a hairline');
    }

    public function test_semester_print_is_a_compact_full_width_table_with_every_student(): void
    {
        $css = $this->css('semester.css');

        $this->assertMatchesRegularExpression('/@media print\s*\{.*\.sem-name\s*\{\s*min-height:\s*0/s', $css);
        $this->assertMatchesRegularExpression('/@media print\s*\{.*tr\.is-paged\s*\{\s*display:\s*table-row\s*!important/s', $css);
        $this->assertMatchesRegularExpression('/@media print\s*\{.*\.semester-page \.sem-master,.*border-radius:\s*0\s*!important/s', $css);
    }

    public function test_roster_print_restores_a_real_table_below_the_phone_breakpoint(): void
    {
        $css = $this->css('roster.css');

        $print = substr($css, (int) strrpos($css, '@media print'));
        $this->assertStringContainsString('.ro-table { display: table;', $print);
        $this->assertStringContainsString('.ro-table thead { display: table-header-group; }', $print);
        $this->assertStringContainsString('tr[data-student-row] { display: table-row;', $print);
        $this->assertStringContainsString('.ro-table .ro-col-order { display: table-cell !important; }', $print);
        $this->assertStringContainsString('.ro-obs-text { display: block; overflow: visible;', $print);
    }

    /**
     * The printed roster table keeps a 1pt inset so the outer half of its right border is not cut off at the page
     * margin (a 1px hairline at 300 dpi). glass.css sets `.ui-table { width: 100% }` and loads after roster.css, so the
     * inset needs a two-class selector to win; see docs/keyboard-audit.md, "Roster PDF right edge".
     */
    public function test_roster_print_table_keeps_its_right_border_inside_the_page_margin(): void
    {
        $roster = $this->css('roster.css');
        $print = substr($roster, (int) strrpos($roster, '@media print'));

        $this->assertStringContainsString('.ui-table.ro-table { width: calc(100% - 1pt); }', $print);
        $this->assertStringContainsString('.ro-table th:nth-child(1) { width: 46pt; }', $print, 'the ORDER heading needs 46pt, 38pt made it touch the border');
        $this->assertStringContainsString('.ui-table { width: 100%;', $this->css('glass.css'), 'the rule the inset has to outrank');
        $this->assertMatchesRegularExpression('/@page\s*\{[^}]*margin:\s*14mm 12mm/s', $this->css('print.css'));
    }

    public function test_print_pdf_tools_are_dev_only_and_not_shipped(): void
    {
        $exclude = File::get(base_path('scripts/release-exclude.txt'));

        $this->assertStringContainsString('/scripts/', $exclude);
        $this->assertStringContainsString('/docs/', $exclude);
        $this->assertFileExists(base_path('scripts/dev/print-pdfs.mjs'));
        $this->assertFileExists(base_path('scripts/dev/capture-panels.mjs'));
        foreach (['print-pdfs.mjs', 'capture-panels.mjs'] as $tool) {
            $source = File::get(base_path('scripts/dev/'.$tool));
            $this->assertStringNotContainsString('require(', $source);
            $this->assertStringNotContainsString("from 'puppeteer", $source);
        }
    }
}
