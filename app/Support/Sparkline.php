<?php

namespace App\Support;

/**
 * Turns a numeric series into SVG geometry for a server-rendered line chart. Pure: no I/O.
 *
 * Null values are gaps: the line is broken there and no point is drawn. The y domain always starts at 0
 * (participation is never negative) unless $min is given.
 */
final class Sparkline
{
    /**
     * @param  array<int, int|float|null>  $values
     * @return array{
     *     width: float, height: float, has_data: bool, count: int, min: float, max: float,
     *     points: array<int, array{index: int, x: float, y: float, value: int|float}>,
     *     segments: array<int, string>, path: string, last: ?array{index: int, x: float, y: float, value: int|float}
     * }
     */
    public static function build(array $values, float $width, float $height, float $padding = 2.0, ?float $min = 0.0, ?float $max = null): array
    {
        $values = array_values($values);
        $present = array_filter($values, fn ($v) => $v !== null);
        $hasData = $present !== [];

        $lo = $min ?? ($hasData ? (float) min($present) : 0.0);
        $hi = $max ?? ($hasData ? (float) max($present) : 1.0);
        if ($hi <= $lo) {
            $hi = $lo + 1.0;
        }

        $n = count($values);
        $innerW = max(0.0, $width - 2 * $padding);
        $innerH = max(0.0, $height - 2 * $padding);

        $points = [];
        $segments = [];
        $run = [];
        foreach ($values as $i => $value) {
            if ($value === null) {
                if ($run !== []) {
                    $segments[] = self::segment($run);
                    $run = [];
                }

                continue;
            }
            $x = $n === 1 ? $width / 2 : $padding + $innerW * $i / ($n - 1);
            $y = $padding + $innerH * (1 - (($value - $lo) / ($hi - $lo)));
            $point = ['index' => $i, 'x' => round($x, 2), 'y' => round($y, 2), 'value' => $value];
            $points[] = $point;
            $run[] = $point;
        }
        if ($run !== []) {
            $segments[] = self::segment($run);
        }

        return [
            'width' => $width,
            'height' => $height,
            'has_data' => $hasData,
            'count' => count($points),
            'min' => $lo,
            'max' => $hi,
            'points' => $points,
            'segments' => $segments,
            'path' => implode(' ', $segments),
            'last' => $points === [] ? null : $points[count($points) - 1],
        ];
    }

    /**
     * @param  array<int, array{x: float, y: float}>  $run
     */
    private static function segment(array $run): string
    {
        $parts = [];
        foreach ($run as $k => $p) {
            $parts[] = ($k === 0 ? 'M' : 'L').sprintf('%.2F', $p['x']).' '.sprintf('%.2F', $p['y']);
        }

        return implode(' ', $parts);
    }
}
