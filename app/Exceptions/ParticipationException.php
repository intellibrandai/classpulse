<?php

namespace App\Exceptions;

use RuntimeException;

class ParticipationException extends RuntimeException
{
    /** Business codes: code => [HTTP status, literal user message]. */
    public const CODES = [
        'weekend' => [422, 'Weekends are not school days.'],
        'future_date' => [422, 'Future dates cannot be edited.'],
        'points_max' => [422, 'Points cannot go above 99.'],
        'points_min' => [422, 'Points cannot go below 0.'],
        'not_recorded' => [422, 'Nothing is recorded for this student yet.'],
        'student_archived' => [422, 'This student is archived.'],
        'nothing_to_do' => [422, 'Nothing to do for this day.'],
        'student_absent' => [409, 'This student is marked absent. Mark present first.'],
        'already_recorded' => [409, 'This student already has a record for this day.'],
        'already_absent' => [409, 'This student is already marked absent.'],
        'not_absent' => [409, 'This student is not marked absent.'],
        'nothing_to_undo' => [409, 'Nothing to undo for this day.'],
        'unchanged' => [409, 'The points already have this value.'],
        'not_found' => [404, 'Not found.'],
        'validation' => [422, 'The operation is not valid.'],
    ];

    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(string $code): self
    {
        [$status, $message] = self::CODES[$code];

        return new self($code, $status, $message);
    }
}
