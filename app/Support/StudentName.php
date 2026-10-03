<?php

namespace App\Support;

/**
 * Pure name helpers for the roster: the "Last name" sort key and avatar initials.
 * `Last, First` uses the part before the comma; otherwise the last word is the last name.
 */
final class StudentName
{
    public static function lastNameKey(string $name): string
    {
        $name = trim($name);
        if (str_contains($name, ',')) {
            $last = trim(explode(',', $name, 2)[0]);
        } else {
            $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $last = $words === [] ? '' : $words[count($words) - 1];
        }

        return mb_strtolower($last).'|'.mb_strtolower($name);
    }

    public static function initials(string $name): string
    {
        $name = trim($name);
        if (str_contains($name, ',')) {
            [$last, $first] = array_map('trim', explode(',', $name, 2)) + ['', ''];
            $words = array_values(array_filter([$first, $last], fn (string $w) => $w !== ''));
            $words = array_map(fn (string $w) => (preg_split('/\s+/u', $w) ?: [''])[0], $words);
        } else {
            $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $words = count($words) > 1 ? [$words[0], $words[count($words) - 1]] : $words;
        }
        $letters = array_map(fn (string $w) => mb_strtoupper(mb_substr($w, 0, 1)), $words);

        return implode('', array_slice($letters, 0, 2)) ?: '?';
    }
}
