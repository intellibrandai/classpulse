<?php

namespace App\Support;

/**
 * Avatar initials: up to two letters from the name, else the first letter of the e-mail, else "?". Pure.
 */
class Initials
{
    public static function of(?string $name, ?string $email = null): string
    {
        $words = preg_split('/[\s._-]+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words !== []) {
            $letters = mb_substr($words[0], 0, 1);
            if (count($words) > 1) {
                $letters .= mb_substr($words[count($words) - 1], 0, 1);
            }

            return mb_strtoupper($letters);
        }

        $mail = trim((string) $email);

        return $mail !== '' ? mb_strtoupper(mb_substr($mail, 0, 1)) : '?';
    }
}
