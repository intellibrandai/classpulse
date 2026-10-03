@php
    $d = fn (string $key, $fallback) => $scope === 'details' ? old($key) : $fallback;
    $c = $currentClass;
    $classKeys = ['name', 'title', 'period_label', 'schedule', 'room', 'roster_cap', 'subject_description'];
    $periodKeys = ['semester_start', 'semester_end', 'q1_label', 'q1_start', 'q1_end', 'q2_label', 'q2_start', 'q2_end'];
    $classIssues = $scope === 'details' ? count(array_filter($classKeys, fn ($k) => $errors->has($k))) : 0;
    $periodIssues = $scope === 'details' ? count(array_filter($periodKeys, fn ($k) => $errors->has($k))) : 0;
@endphp
<section class="ro-card glass glass-violet ro-settings" aria-labelledby="details-title">
    <header class="ro-card-head">
        <h2 id="details-title" class="ro-card-title"><span class="ui-icon-chip ui-icon-chip-violet"><x-icon name="sliders" class="icon-18" /></span>Class Details: {{ $c->name }}</h2>
        <span class="ui-badge ui-badge-violet">Active block</span>
    </header>
    {{-- One form: collapsed groups still submit, and the single Save button stays reachable (sticky). --}}
    <form method="POST" action="{{ url('/classes/'.$c->id) }}" class="ro-form" novalidate>
        @csrf
        @method('PUT')
        <input type="hidden" name="form" value="details">

        <details class="ro-group" id="class-details" data-ro-group open>
            <summary class="ro-group-summary">
                <span class="ui-icon-chip ui-icon-chip-violet"><x-icon name="clipboard-list" class="icon-18" /></span>
                <span class="ro-group-text"><span class="ro-group-title">Class details</span><span class="ro-group-sub">Code, title, schedule, room, cap</span></span>
                @if ($classIssues > 0)<span class="ui-badge ui-badge-absent">{{ $classIssues }} to fix</span>@endif
                <x-icon name="chevron-down" class="ro-group-caret icon-18" />
            </summary>
            <div class="ro-group-body">
                <div class="ro-grid ro-grid-2">
                @include('roster.partials.field', ['id' => 'edit-name', 'name' => 'name', 'label' => 'Course code', 'value' => $d('name', $c->name), 'max' => 60, 'required' => true, 'error' => $err('name', 'details')])
                @include('roster.partials.field', ['id' => 'edit-title', 'name' => 'title', 'label' => 'Course title', 'value' => $d('title', $c->title), 'max' => 120, 'error' => $err('title', 'details')])
                @include('roster.partials.field', ['id' => 'edit-period', 'name' => 'period_label', 'label' => 'Period / Block', 'value' => $d('period_label', $c->period_label), 'max' => 40, 'placeholder' => 'Period 2', 'error' => $err('period_label', 'details')])
                @include('roster.partials.field', ['id' => 'edit-schedule', 'name' => 'schedule', 'label' => 'Schedule', 'value' => $d('schedule', $c->schedule), 'max' => 80, 'placeholder' => '10:15 - 11:35', 'error' => $err('schedule', 'details')])
                @include('roster.partials.field', ['id' => 'edit-room', 'name' => 'room', 'label' => 'Room / Lab', 'value' => $d('room', $c->room), 'max' => 60, 'error' => $err('room', 'details')])
                @include('roster.partials.field', ['id' => 'edit-cap', 'name' => 'roster_cap', 'label' => 'Roster cap', 'type' => 'number', 'value' => $d('roster_cap', $c->roster_cap), 'min' => 1, 'maxnum' => config('classpulse.max_roster'), 'required' => true, 'hint' => 'Up to '.config('classpulse.max_roster').' seats.', 'error' => $err('roster_cap', 'details')])
                @include('roster.partials.field', ['id' => 'edit-subject', 'name' => 'subject_description', 'label' => 'Subject description', 'value' => $d('subject_description', $c->subject_description), 'max' => 120, 'span' => 'ro-span', 'error' => $err('subject_description', 'details')])
                </div>
            </div>
        </details>

        <details class="ro-group" id="periods" data-ro-group @if ($periodIssues > 0) open @endif>
            <summary class="ro-group-summary">
                <span class="ui-icon-chip ui-icon-chip-blue"><x-icon name="calendar" class="icon-18" /></span>
                <span class="ro-group-text"><span class="ro-group-title">Academic periods</span><span class="ro-group-sub">Semester dates, Q1 and Q2</span></span>
                @if ($periodIssues > 0)<span class="ui-badge ui-badge-absent">{{ $periodIssues }} to fix</span>@endif
                <x-icon name="chevron-down" class="ro-group-caret icon-18" />
            </summary>
            <fieldset class="ro-group-body ro-periods">
                <legend class="visually-hidden">Academic periods</legend>
                <div class="ro-grid ro-grid-2">
                @include('roster.partials.field', ['id' => 'edit-start', 'name' => 'semester_start', 'label' => 'Semester start', 'type' => 'date', 'value' => $d('semester_start', $c->semester_start?->format('Y-m-d')), 'error' => $err('semester_start', 'details')])
                @include('roster.partials.field', ['id' => 'edit-end', 'name' => 'semester_end', 'label' => 'Semester end', 'type' => 'date', 'value' => $d('semester_end', $c->semester_end?->format('Y-m-d')), 'error' => $err('semester_end', 'details')])
                </div>
                <p class="ro-hint" id="periods-help">Q1/Q2 appear in Semester Analytics only when dates are set. Leave both dates blank to remove a period.</p>
                @foreach (['q1' => 'Q1', 'q2' => 'Q2'] as $kind => $short)
                @php $pf = $periodForm[$kind]; @endphp
                <div class="ro-period-row" data-period="{{ $kind }}">
                    <span class="ro-period-tag ui-badge ui-badge-info">{{ $short }}</span>
                    @include('roster.partials.field', ['id' => $kind.'-label', 'name' => $kind.'_label', 'label' => $short.' label', 'value' => $d($kind.'_label', $pf['label']), 'max' => 40, 'placeholder' => $pf['default_label'], 'error' => $err($kind.'_label', 'details')])
                    @include('roster.partials.field', ['id' => $kind.'-start', 'name' => $kind.'_start', 'label' => $short.' start', 'type' => 'date', 'value' => $d($kind.'_start', $pf['start']), 'error' => $err($kind.'_start', 'details')])
                    @include('roster.partials.field', ['id' => $kind.'-end', 'name' => $kind.'_end', 'label' => $short.' end', 'type' => 'date', 'value' => $d($kind.'_end', $pf['end']), 'error' => $err($kind.'_end', 'details')])
                </div>
                @endforeach
            </fieldset>
        </details>

        <div class="ro-form-actions ro-save-bar">
            <button type="submit" class="ui-btn ui-btn-primary"><x-icon name="check" class="icon-18" />Save class details</button>
            <span class="ro-save-note">Saves every section, open or closed.</span>
        </div>
    </form>
</section>
