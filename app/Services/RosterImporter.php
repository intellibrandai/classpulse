<?php

namespace App\Services;

use App\Models\SchoolClass;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RosterImporter
{
    private const SESSION_PREFIX = 'classpulse.import.';

    private const OVER_CAPACITY = 'Over capacity: this row does not fit in the class.';

    /**
     * Parses a CSV/TXT file or pasted names into preview rows and stores them in the session.
     *
     * @return array{token: string, rows: array<int, array{row: int, name: string, student_number: ?string, status: string, reason: string}>, capacity: array{active: int, limit: int, remaining: int, over_rows: array<int, int>, message: ?string}}
     */
    public function preview(SchoolClass $class, ?UploadedFile $file, ?string $pasted): array
    {
        $data = array_filter(['file' => $file, 'pasted' => $pasted], fn ($value) => $value !== null && $value !== '');

        Validator::make($data, [
            'file' => ['required_without:pasted', 'prohibits:pasted', 'file', 'max:256', 'extensions:csv,txt'],
            'pasted' => ['required_without:file', 'string', 'max:262144'],
        ])->validate();

        $text = $file !== null ? (string) file_get_contents($file->getRealPath()) : (string) $pasted;
        $rows = $this->classify($class, $this->parse($text));

        $token = Str::random(40);
        session()->put(self::SESSION_PREFIX.$token, ['class_id' => $class->id, 'rows' => $rows]);

        return ['token' => $token, 'rows' => $rows, 'capacity' => $this->capacity($class, $rows)];
    }

    /**
     * What the preview shows BEFORE anything is saved: how many seats are left and which rows do not fit.
     *
     * @param  array<int, array{row: int, status: string, reason: string}>  $rows
     * @return array{active: int, limit: int, remaining: int, over_rows: array<int, int>, message: ?string}
     */
    private function capacity(SchoolClass $class, array $rows): array
    {
        $active = $class->activeStudentCount();
        $remaining = $class->remainingCapacity($active);
        $over = array_values(array_map(
            fn (array $row) => $row['row'],
            array_filter($rows, fn (array $row) => $row['reason'] === self::OVER_CAPACITY),
        ));

        $message = null;
        if ($over !== []) {
            $message = $remaining === 0
                ? $class->fullMessage($active).' '.$this->rowsText($over).' will not be imported.'
                : "Only {$remaining} more ".($remaining === 1 ? 'student fits' : 'students fit')." ({$active} of {$class->seatLimit()} active). ".$this->rowsText($over).(count($over) === 1 ? ' exceeds' : ' exceed').' the limit and will not be imported.';
        }

        return ['active' => $active, 'limit' => $class->seatLimit(), 'remaining' => $remaining, 'over_rows' => $over, 'message' => $message];
    }

    /**
     * "Row 36" / "Rows 36–38" / "Rows 36, 40–41".
     *
     * @param  array<int, int>  $numbers  ascending row numbers
     */
    private function rowsText(array $numbers): string
    {
        $ranges = [];
        foreach ($numbers as $n) {
            $last = array_key_last($ranges);
            if ($last !== null && $ranges[$last][1] === $n - 1) {
                $ranges[$last][1] = $n;
            } else {
                $ranges[] = [$n, $n];
            }
        }
        $parts = array_map(fn (array $r) => $r[0] === $r[1] ? (string) $r[0] : $r[0].'–'.$r[1], $ranges);

        return (count($numbers) === 1 ? 'Row ' : 'Rows ').implode(', ', $parts);
    }

    /**
     * Creates the selected OK and WARNING rows of a stored preview in one transaction.
     *
     * @param  array<int, int|string>  $selectedRowNumbers
     */
    public function commit(SchoolClass $class, string $token, array $selectedRowNumbers): int
    {
        $stored = session(self::SESSION_PREFIX.$token);
        if (! is_array($stored) || ($stored['class_id'] ?? null) !== $class->id) {
            throw ValidationException::withMessages(['token' => 'The import preview expired. Upload the file again.']);
        }

        $selected = array_map('intval', $selectedRowNumbers);
        $valid = array_values(array_filter(
            $stored['rows'],
            fn (array $row) => $row['status'] !== 'ERROR' && in_array($row['row'], $selected, true),
        ));

        $created = DB::transaction(function () use ($class, $valid) {
            $locked = SchoolClass::query()->whereKey($class->id)->lockForUpdate()->firstOrFail();
            // Re-counted here, under the class lock, so a student added since the preview (or by another request) still counts.
            $active = $locked->activeStudentCount();
            $remaining = $locked->remainingCapacity($active);
            if (count($valid) > $remaining) {
                throw ValidationException::withMessages(['roster_cap' => $remaining === 0
                    ? $locked->fullMessage($active).' Nothing was imported.'
                    : "Only {$remaining} more ".($remaining === 1 ? 'student fits' : 'students fit')." ({$active} of {$locked->seatLimit()} active), but ".count($valid).' rows are selected. Nothing was imported.']);
            }
            foreach ($valid as $row) {
                $locked->students()->create(['display_name' => $row['name'], 'student_number' => $row['student_number']]);
            }

            return count($valid);
        });

        session()->forget(self::SESSION_PREFIX.$token);

        return $created;
    }

    /**
     * @return array<int, array{name: string, student_number: ?string}>
     */
    private function parse(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $text) ?: [],
            fn (string $line) => trim($line) !== '',
        ));
        if ($lines === []) {
            return [];
        }

        $delimiter = $this->delimiter($lines[0]);
        $header = array_map(fn ($cell) => mb_strtolower(trim((string) $cell)), str_getcsv($lines[0], $delimiter, '"', ''));

        $nameCol = array_search('name', $header, true);
        $firstCol = array_search('first_name', $header, true);
        $lastCol = array_search('last_name', $header, true);
        $numberCol = array_search('student_number', $header, true);
        $hasHeader = $nameCol !== false || ($firstCol !== false && $lastCol !== false);

        $out = [];
        foreach (array_slice($lines, $hasHeader ? 1 : 0) as $line) {
            $cells = str_getcsv($line, $delimiter, '"', '');
            if ($hasHeader) {
                $name = $nameCol !== false
                    ? (string) ($cells[$nameCol] ?? '')
                    : trim((string) ($cells[$firstCol] ?? '')).' '.trim((string) ($cells[$lastCol] ?? ''));
                $number = $numberCol !== false ? trim((string) ($cells[$numberCol] ?? '')) : '';
            } else {
                $name = (string) ($cells[0] ?? '');
                $number = '';
            }
            $out[] = ['name' => $this->collapse($name), 'student_number' => $number === '' ? null : $number];
        }

        return $out;
    }

    private function delimiter(string $firstLine): string
    {
        $best = ',';
        $bestCount = 0;
        foreach ([',', ';', "\t"] as $candidate) {
            $count = substr_count($firstLine, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function collapse(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * @param  array<int, array{name: string, student_number: ?string}>  $parsed
     * @return array<int, array{row: int, name: string, student_number: ?string, status: string, reason: string}>
     */
    private function classify(SchoolClass $class, array $parsed): array
    {
        $existing = $class->students()->get(['display_name', 'student_number']);
        $existingNames = $existing->map(fn ($s) => mb_strtolower($this->collapse($s->display_name)))->all();
        $existingNumbers = $existing->pluck('student_number')->filter(fn ($n) => $n !== null && $n !== '')->all();

        $remaining = $class->remainingCapacity();
        $okCount = 0;
        $seenNames = [];
        $seenNumbers = [];
        $rows = [];

        foreach ($parsed as $index => $item) {
            $rowNumber = $index + 1;
            $name = $item['name'];
            $number = $item['student_number'];
            $key = mb_strtolower($name);
            $status = 'OK';
            $reason = '';

            if ($name === '') {
                $status = 'ERROR';
                $reason = 'Name is empty.';
            } elseif (mb_strlen($name) > 120) {
                $status = 'ERROR';
                $reason = 'Name is longer than 120 characters.';
            } elseif ($number !== null && mb_strlen($number) > 40) {
                $status = 'ERROR';
                $reason = 'Student number is longer than 40 characters.';
            } else {
                $earlier = $seenNames[$key] ?? ($number !== null ? ($seenNumbers[$number] ?? null) : null);
                if (in_array($key, $existingNames, true) || ($number !== null && in_array($number, $existingNumbers, true))) {
                    $status = 'WARNING';
                    $reason = 'Matches an existing student.';
                } elseif ($earlier !== null) {
                    $status = 'WARNING';
                    $reason = 'Repeats row '.$earlier.'.';
                } elseif ($okCount >= $remaining) {
                    $status = 'ERROR';
                    $reason = self::OVER_CAPACITY;
                } else {
                    $okCount++;
                }
                $seenNames[$key] ??= $rowNumber;
                if ($number !== null) {
                    $seenNumbers[$number] ??= $rowNumber;
                }
            }

            $rows[] = ['row' => $rowNumber, 'name' => $name, 'student_number' => $number, 'status' => $status, 'reason' => $reason];
        }

        return $rows;
    }
}
