@php
    $status = $entry['status'];
    $points = $entry['points'];
    $name = $student->display_name;
    $initials = collect(preg_split('/\s+/', trim($name)))->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $state = match (true) {
        $status === 'absent' => 'absent',
        $status === 'present' && $points === 0 => 'zero',
        $status === 'present' => 'present',
        default => 'untouched',
    };
    $badge = ['untouched' => 'badge-untouched', 'zero' => 'badge-zero', 'present' => 'badge-active', 'absent' => 'badge-absent'][$state];
    $label = ['untouched' => 'Not recorded', 'zero' => 'Present · 0', 'present' => 'Present', 'absent' => 'Absent'][$state];
    $canDecrement = $status === 'present' && $points > 0;
@endphp
<article class="student-card" data-student-id="{{ $student->id }}" data-status="{{ $status }}" data-state="{{ $state }}">
    <div class="card-top-row">
        <div class="student-meta">
            <span class="avatar-sm" aria-hidden="true">{{ $initials }}</span>
            <div class="student-name-box">
                <button type="button" class="student-name" title="{{ $name }}" aria-label="Show details for {{ $name }}" aria-pressed="false">{{ $name }}</button>
                <span class="student-sub"><a class="history-link" href="{{ url('/students/'.$student->id) }}" aria-label="History for {{ $name }}">History</a></span>
            </div>
        </div>
        <span class="card-badges">
            <span class="note-indicator" data-note-indicator @if (! ($hasNote ?? false)) hidden @endif title="A note is saved for this day"><x-icon name="message-square" class="icon-16" /><span class="visually-hidden">Has a note for this day</span></span>
            <span class="status-badge {{ $badge }}" data-role="status">{{ $label }}</span>
        </span>
    </div>
    <div class="score-cluster">
        <button type="button" class="btn-score-adjust btn-minus" data-action="decrement" aria-label="Remove one point from {{ $name }}" @disabled(! $canDecrement)><x-icon name="minus" class="icon-20" /></button>
        <div class="score-display-center">
            <span class="score-number">{{ $status === 'present' ? $points : '—' }}</span>
            <span class="score-lbl">Points today</span>
        </div>
        <button type="button" class="btn-score-adjust btn-plus" data-action="increment" aria-label="Add one point to {{ $name }}" @disabled($status === 'absent')><x-icon name="plus" class="icon-20" /></button>
    </div>
    <div class="card-footer-controls">
        @if ($status === 'none')
            <button type="button" class="btn-record-zero" data-action="set_zero" aria-label="Record 0 for {{ $name }}">Record 0</button>
        @endif
        @if ($status === 'absent')
            <button type="button" class="btn-absent-toggle" data-action="absent_off" aria-label="Marked Absent (Tap to clear) for {{ $name }}">Marked Absent (Tap to clear)</button>
        @else
            <button type="button" class="btn-absent-toggle" data-action="absent_on" aria-label="Mark Absent for {{ $name }}">Mark Absent</button>
        @endif
    </div>
</article>
