<?php

namespace App\Support;

/**
 * Turns a numeric series into SVG rect geometry for a server-rendered bar chart. Pure: no I/O.
 *
 * Every index yields one entry so labels line up; null values are gaps (is_gap true, height 0). The y domain is
 * 0..max(values, $max). A recorded zero is a bar of height 0 with is_gap false.
 */
final class BarSeries
{
    /**
     * @param  array<int, int|float|null>  $values
     * @return array{
     *     width: float, height: float, has_data: bool, max: float, baseline_y: float,
     *     peak_index: ?int, last_index: ?int,
     *     bars: array<int, array{index: int, x: float, y: float, width: float, height: float, value: int|float|null, is_gap: bool, is_peak: bool, is_last: bool}>
     * }
     */
    public static function build(array $values, float $width, float $height, float $gap = 4.0, float $padding = 0.0, ?float $max = null): array
    {
        $values = array_values($values);
        $present = array_filter($values, fn ($v) => $v !== null);
        $hasData = $present !== [];

        $hi = max($max ?? 0.0, $hasData ? (float) max($present) : 0.0);
        if ($hi <= 0.0) {
            $hi = 1.0;
        }

        $n = count($values);
        $innerH = max(0.0, $height - 2 * $padding);
        $innerW = max(0.0, $width - 2 * $padding);
        $barW = $n === 0 ? 0.0 : max(0.0, ($innerW - $gap * ($n - 1)) / $n);
        $baseline = $padding + $innerH;

        $peak = null;
        $last = null;
        foreach ($values as $i => $value) {
            if ($value === null) {
                continue;
            }
            $last = $i;
            if ($value > 0 && ($peak === null || $value > $values[$peak])) {
                $peak = $i;
            }
        }

        $bars = [];
        foreach ($values as $i => $value) {
            $h = $value === null ? 0.0 : $innerH * $value / $hi;
            $bars[] = [
                'index' => $i,
                'x' => round($padding + $i * ($barW + $gap), 2),
                'y' => round($baseline - $h, 2),
                'width' => round($barW, 2),
                'height' => round($h, 2),
                'value' => $value,
                'is_gap' => $value === null,
                'is_peak' => $i === $peak,
                'is_last' => $i === $last,
            ];
        }

        return [
            'width' => $width,
            'height' => $height,
            'has_data' => $hasData,
            'max' => $hi,
            'baseline_y' => round($baseline, 2),
            'peak_index' => $peak,
            'last_index' => $last,
            'bars' => $bars,
        ];
    }
}
