<?php

namespace App\Support;

/**
 * Resolves the selectable periods of a class (Full Semester, Q1, Q2) and validates period settings. Pure: no I/O.
 *
 * Dates are 'Y-m-d' strings. A period is only offered when it is configured; nothing is invented.
 */
final class AcademicPeriods
{
    public const FULL = 'full';

    public const FULL_LABEL = 'Full Semester';

    /**
     * @param  array<string, array{label: string, starts_on: string, ends_on: string}>  $configured  keyed by 'q1'/'q2'
     * @return array<string, array{key: string, label: string, from: ?string, to: ?string, configured: bool}>
     *                                                                                                        keyed by period key: 'full' first, then the configured quarters in q1, q2 order
     */
    public static function resolve(?string $semesterStart, ?string $semesterEnd, array $configured, ?string $firstRecorded, ?string $lastRecorded): array
    {
        $from = $semesterStart ?? $firstRecorded;
        $to = $semesterEnd ?? $lastRecorded;
        if ($from === null || $to === null || $to < $from) {
            $from = $to = null;
        }

        $periods = [self::FULL => [
            'key' => self::FULL,
            'label' => self::FULL_LABEL,
            'from' => $from,
            'to' => $to,
            'configured' => $semesterStart !== null || $semesterEnd !== null,
        ]];

        foreach (['q1', 'q2'] as $kind) {
            if (isset($configured[$kind])) {
                $periods[$kind] = [
                    'key' => $kind,
                    'label' => $configured[$kind]['label'],
                    'from' => $configured[$kind]['starts_on'],
                    'to' => $configured[$kind]['ends_on'],
                    'configured' => true,
                ];
            }
        }

        return $periods;
    }

    /**
     * The requested period key, or Full Semester when it is unknown or not configured.
     *
     * @param  array<string, array{key: string, label: string, from: ?string, to: ?string, configured: bool}>  $periods
     * @return array{key: string, label: string, from: ?string, to: ?string, configured: bool}
     */
    public static function select(array $periods, ?string $key): array
    {
        return $periods[$key ?? self::FULL] ?? $periods[self::FULL];
    }

    /**
     * The period immediately before $period, of the same kind when one exists: Q2 compares with Q1; Q1 and the
     * full semester have no comparable previous period. Null when not configured.
     *
     * @param  array<string, array{key: string, label: string, from: ?string, to: ?string, configured: bool}>  $periods
     * @param  array{key: string, label: string, from: ?string, to: ?string, configured: bool}  $period
     * @return ?array{key: string, label: string, from: ?string, to: ?string, configured: bool}
     */
    public static function previous(array $periods, array $period): ?array
    {
        return $period['key'] === 'q2' ? ($periods['q1'] ?? null) : null;
    }

    /**
     * Validates the semester range and the two quarters. Returns field => message (empty when valid).
     * Fields: semester_start, semester_end, q1_start, q1_end, q2_start, q2_end.
     * Rules: start <= end; a quarter lies inside the semester bounds that are set; Q1 and Q2 must not overlap.
     *
     * @param  array{start: ?string, end: ?string}|null  $q1
     * @param  array{start: ?string, end: ?string}|null  $q2
     * @return array<string, string>
     */
    public static function validate(?string $semesterStart, ?string $semesterEnd, ?array $q1, ?array $q2): array
    {
        $errors = [];
        if ($semesterStart !== null && $semesterEnd !== null && $semesterEnd < $semesterStart) {
            $errors['semester_end'] = 'The semester end must be on or after its start.';
        }

        foreach (['q1' => $q1, 'q2' => $q2] as $kind => $range) {
            $name = strtoupper($kind);
            $start = $range['start'] ?? null;
            $end = $range['end'] ?? null;
            if (($start === null) !== ($end === null)) {
                $errors[$kind.'_'.($start === null ? 'start' : 'end')] = "{$name} needs both a start and an end date.";

                continue;
            }
            if ($start === null) {
                continue;
            }
            if ($end < $start) {
                $errors[$kind.'_end'] = "{$name} must end on or after its start.";

                continue;
            }
            if ($semesterStart !== null && $start < $semesterStart) {
                $errors[$kind.'_start'] = "{$name} cannot start before the semester start.";
            }
            if ($semesterEnd !== null && $end > $semesterEnd) {
                $errors[$kind.'_end'] = "{$name} cannot end after the semester end.";
            }
        }

        $a = self::range($q1);
        $b = self::range($q2);
        if ($a !== null && $b !== null && ! isset($errors['q1_end'], $errors['q2_end']) && $a[0] <= $b[1] && $b[0] <= $a[1]) {
            $errors['q2_start'] ??= 'Q1 and Q2 must not overlap.';
        }

        return $errors;
    }

    /**
     * @param  array{start: ?string, end: ?string}|null  $range
     * @return ?array{0: string, 1: string}
     */
    private static function range(?array $range): ?array
    {
        $start = $range['start'] ?? null;
        $end = $range['end'] ?? null;

        return $start !== null && $end !== null && $end >= $start ? [$start, $end] : null;
    }
}
