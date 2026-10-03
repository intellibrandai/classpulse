@php
    $status = $entry['status'];
    $points = $entry['points'];
    $badge = match (true) {
        $status === 'absent' => 'badge-absent',
        $status === 'present' && $points === 0 => 'badge-zero',
        $status === 'present' => 'badge-active',
        default => 'badge-untouched',
    };
    $label = match (true) {
        $status === 'absent' => 'Absent',
        $status === 'present' && $points === 0 => 'Present · 0',
        $status === 'present' => 'Present',
        default => 'Not recorded',
    };
    $sub = match (true) {
        $status === 'absent' => 'Absent today',
        $status === 'present' => 'Present · '.$points.($points === 1 ? ' pt' : ' pts').' today',
        default => 'Not recorded today',
    };
@endphp
@php
    $noteId = 'note-'.$student->id;
    $savedNote = $noteBody ?? '';
@endphp
<section class="inspector-student" data-panel-student="{{ $student->id }}" data-status="{{ $status }}" hidden>
    <div class="inspector-header">
        <div class="inspector-heading">
            <div class="inspector-name">{{ $student->display_name }}</div>
            @if (filled($student->preferred_name))<div class="inspector-pref">Preferred: {{ $student->preferred_name }}</div>@endif
            <div class="inspector-sub" data-panel-sub>{{ $sub }}</div>
        </div>
        <span class="status-badge {{ $badge }}" data-panel-status>{{ $label }}</span>
    </div>
    <div class="inspector-label">Current week breakdown</div>
    <ol class="mini-breakdown-list">
        @foreach ($rows as $row)
            @php
                $value = match ($row['state']) {
                    'present' => $row['points'] === 0 ? 'Present · 0' : $row['points'].($row['points'] === 1 ? ' pt' : ' pts'),
                    'absent' => 'Absent',
                    'upcoming' => 'Upcoming',
                    default => 'Not recorded',
                };
            @endphp
            <li class="mini-day-row" data-state="{{ $row['state'] }}" data-selected="{{ $row['selected'] ? 'true' : 'false' }}" data-today="{{ $row['today'] ? 'true' : 'false' }}" @if ($row['selected']) data-day-row @endif>
                <span class="mini-day-name">{{ $row['label'] }}@if ($row['today']) <span class="mini-day-tag">Today</span>@elseif ($row['selected']) <span class="mini-day-tag">Viewing</span>@endif</span>
                <strong class="mini-day-value" @if ($row['selected']) data-day-value @endif>{{ $value }}</strong>
            </li>
        @endforeach
    </ol>

    <div class="inspector-label">Semester cadence trend</div>
    @include('daily.cadence', ['weeks' => $cadence])

    <div class="note-editor" data-note-editor data-note-url="{{ url('/api/classes/'.$currentClass->id.'/students/'.$student->id.'/notes/'.$date) }}" data-note-saved="{{ $savedNote }}" data-student-name="{{ $student->display_name }}">
        <label class="inspector-label" for="{{ $noteId }}">Session anecdotal remark</label>
        <textarea id="{{ $noteId }}" class="ui-input note-input" data-note-input rows="4" maxlength="2000" placeholder="Log a quick observation…" aria-describedby="{{ $noteId }}-count {{ $noteId }}-status">{{ $savedNote }}</textarea>
        <div class="note-meta">
            <span class="note-count" id="{{ $noteId }}-count" data-note-count>{{ mb_strlen($savedNote) }} / 2000</span>
            <span class="note-status" id="{{ $noteId }}-status" data-note-status role="status" aria-live="polite"></span>
        </div>
        <button type="button" class="ui-btn ui-btn-primary note-save" data-note-save disabled><x-icon name="check" class="icon-16" />Save Student Note</button>
        <p class="note-hint">Saved for {{ $student->display_name }} on {{ $dateText }}. Clearing the text and saving removes the note.</p>
        @if (! empty($recent))
            <div class="inspector-label note-recent-label">Recent notes</div>
            <ul class="note-recent">
                @foreach ($recent as $item)
                    <li><time datetime="{{ $item['date'] }}">{{ $item['label'] }}</time> <span>{{ $item['excerpt'] }}</span></li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
