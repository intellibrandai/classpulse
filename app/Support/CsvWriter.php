<?php

namespace App\Support;

final class CsvWriter
{
    /**
     * One CSV line: null => empty, int => digits, string => sanitised and always quoted. Ends with LF.
     *
     * @param  array<int, int|string|null>  $cells
     */
    public static function line(array $cells): string
    {
        $out = [];
        foreach ($cells as $cell) {
            if ($cell === null) {
                $out[] = '';
            } elseif (is_int($cell)) {
                $out[] = (string) $cell;
            } else {
                $text = (string) $cell;
                if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $text = "'".$text;
                }
                $out[] = '"'.str_replace('"', '""', $text).'"';
            }
        }

        return implode(',', $out)."\n";
    }

    public static function filename(string $kind, string $slugSource, string $period, string $exportDate): string
    {
        return 'classpulse-'.$kind.'-'.self::slug($slugSource).'-'.$period.'-exported-'.$exportDate.'.csv';
    }

    public static function slug(string $text): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-');
    }
}
