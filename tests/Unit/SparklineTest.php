<?php

namespace Tests\Unit;

use App\Support\BarSeries;
use App\Support\Sparkline;
use PHPUnit\Framework\TestCase;

class SparklineTest extends TestCase
{
    public function test_line_scales_values_into_the_padded_box(): void
    {
        $line = Sparkline::build([0, 5, 10], 104, 54, 2);

        $this->assertTrue($line['has_data']);
        $this->assertSame(3, $line['count']);
        $this->assertSame([2.0, 52.0, 102.0], array_column($line['points'], 'x'));
        $this->assertSame([52.0, 27.0, 2.0], array_column($line['points'], 'y'));
        $this->assertSame('M2.00 52.00 L52.00 27.00 L102.00 2.00', $line['path']);
        $this->assertSame(2, $line['last']['index']);
    }

    public function test_gaps_break_the_line_and_draw_no_point(): void
    {
        $line = Sparkline::build([2, null, 4, 4, null, 1], 100, 40, 0);

        $this->assertSame(4, $line['count']);
        $this->assertSame([0, 2, 3, 5], array_column($line['points'], 'index'));
        $this->assertCount(3, $line['segments']);
        $this->assertStringStartsWith('M0.00 ', $line['segments'][0]);
        $this->assertStringNotContainsString('L', $line['segments'][0]);
        $this->assertStringContainsString(' L', $line['segments'][1]);
    }

    public function test_empty_and_all_gap_series_have_no_data(): void
    {
        foreach ([[], [null, null]] as $values) {
            $line = Sparkline::build($values, 100, 40);
            $this->assertFalse($line['has_data']);
            $this->assertSame([], $line['points']);
            $this->assertSame('', $line['path']);
            $this->assertNull($line['last']);
        }
    }

    public function test_single_point_is_centred_and_flat_series_does_not_divide_by_zero(): void
    {
        $one = Sparkline::build([3], 100, 40, 2);
        $this->assertSame(50.0, $one['points'][0]['x']);

        $zeros = Sparkline::build([0, 0, 0], 100, 40, 2);
        $this->assertSame([38.0, 38.0, 38.0], array_column($zeros['points'], 'y'));
    }

    public function test_bars_keep_one_slot_per_index_with_gaps_and_a_recorded_zero(): void
    {
        $bars = BarSeries::build([4, null, 0, 2], 100, 50, 4);

        $this->assertTrue($bars['has_data']);
        $this->assertCount(4, $bars['bars']);
        $this->assertSame(22.0, $bars['bars'][0]['width']);
        $this->assertSame(50.0, $bars['bars'][0]['height']);
        $this->assertSame(0.0, $bars['bars'][0]['y']);
        $this->assertTrue($bars['bars'][1]['is_gap']);
        $this->assertSame(0.0, $bars['bars'][1]['height']);
        $this->assertFalse($bars['bars'][2]['is_gap']);
        $this->assertSame(0.0, $bars['bars'][2]['height']);
        $this->assertSame(25.0, $bars['bars'][3]['height']);
        $this->assertSame(0, $bars['peak_index']);
        $this->assertSame(3, $bars['last_index']);
        $this->assertTrue($bars['bars'][3]['is_last']);
    }

    public function test_bar_ties_highlight_the_earliest_peak_and_empty_series_is_safe(): void
    {
        $tie = BarSeries::build([3, 1, 3], 90, 30, 0);
        $this->assertSame(0, $tie['peak_index']);

        $empty = BarSeries::build([null, null], 90, 30);
        $this->assertFalse($empty['has_data']);
        $this->assertNull($empty['peak_index']);
        $this->assertNull($empty['last_index']);

        $zeros = BarSeries::build([0, 0], 90, 30);
        $this->assertNull($zeros['peak_index']);
        $this->assertSame([], BarSeries::build([], 90, 30)['bars']);
    }
}
