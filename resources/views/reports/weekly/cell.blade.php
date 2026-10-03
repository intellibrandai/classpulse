@php
    $status = $cell['status'];
    $points = $cell['points'];
    $heat = \App\Support\WeeklyView::heat($status, $points);
    $state = match ($status) {
        'absent' => 'Absent',
        'present' => 'Present, '.$points.($points === 1 ? ' point' : ' points'),
        default => 'Not recorded',
    };
    $text = match ($status) {
        'absent' => 'A',
        'present' => (string) $points,
        default => '—',
    };
    $when = $day['weekday'].' '.$day['label'];
    $locked = $archived ? 'Archived students are read-only' : (! $day['editable'] ? 'Future days cannot be edited' : null);
@endphp
<button type="button" @class(['wm-cell', $heat => $heat !== '', 'wm-cell-none' => $status === 'none']) data-cell data-student-id="{{ $studentId }}" data-date="{{ $cell['date'] }}" data-when="{{ $when }}" data-status="{{ $status }}" data-points="{{ $points }}" aria-haspopup="dialog" aria-label="{{ $name }}, {{ $when }}: {{ $state }}. {{ $locked ?? 'Open editor' }}" title="{{ $locked ?? $state }}" @disabled($locked !== null)><span class="wm-cell-text" aria-hidden="true">{{ $text }}</span></button>
