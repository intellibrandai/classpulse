@if ($currentClass === null)
    <x-layouts.app title="Daily Tracker" active="daily" :classes="$classes" :current-class="null">
        <section class="empty-state">
            <h1>Create your first class</h1>
            <p><a class="btn btn-primary" href="{{ url('/roster') }}">Go to Class Roster &amp; Settings</a></p>
        </section>
    </x-layouts.app>
@else
    @php
        $summary = $day['summary'];
        $studentCount = count($cards);
        $description = trim((string) $currentClass->subject_description);
    @endphp
    @push('head')
        <link rel="stylesheet" href="{{ asset('css/daily.css') }}">
    @endpush
    <x-layouts.app
        title="Daily Tracker"
        active="daily"
        :classes="$classes"
        :current-class="$currentClass"
        :data-api="'/api/classes/'.$currentClass->id.'/days/'.$date"
        :data-day-version="$day['day_version']"
        print-title="Day Slip"
        :print-label="$dateText"
    >
        <x-slot:actions>
            <button type="button" id="undo-btn" class="btn-undo" aria-label="Undo last action" title="Undo last action" @disabled(! $day['can_undo'])><x-icon name="undo" class="icon-16" /><span class="btn-label">Undo</span></button>
        </x-slot:actions>

        <x-slot:status>
            <span id="save-pill" class="sync-badge" data-state="saved" role="status" aria-live="polite"><span class="sync-dot" aria-hidden="true"></span><span data-save-text>Saved</span></span>
        </x-slot:status>

        <x-slot:shortcuts>
            <span>Shortcuts: [/] Search · [Esc] Close</span>
        </x-slot:shortcuts>

        @if (session('status'))
            <p class="flash-status no-print" role="status"><x-icon name="check-circle" class="icon-16" />{{ session('status') }}</p>
        @endif

        <div class="day-toolbar ui-toolbar no-print" role="toolbar" aria-label="Day tools">
            <div class="toolbar-group toolbar-date">
                <div class="date-navigator ui-segmented-pill">
                    <a class="btn-icon" href="{{ $previousUrl }}" aria-label="Previous school day" title="Previous school day"><x-icon name="chevron-left" class="icon-18" /></a>
                    <div class="date-indicator-badge">
                        <x-icon name="calendar" class="date-icon icon-18" />
                        <h1 class="date-text" aria-label="{{ $dateText }}"><span class="date-full" aria-hidden="true">{{ $dateText }}</span><span class="date-short" aria-hidden="true">{{ $dateShort }}</span></h1>
                    </div>
                    @if ($nextUrl)
                        <a class="btn-icon" href="{{ $nextUrl }}" aria-label="Next school day" title="Next school day"><x-icon name="chevron-right" class="icon-18" /></a>
                    @else
                        <button type="button" class="btn-icon" aria-label="Next school day" disabled><x-icon name="chevron-right" class="icon-18" /></button>
                    @endif
                </div>
                <a class="ui-btn toolbar-today" href="{{ $todayUrl }}">Today</a>
            </div>

            <div class="toolbar-group toolbar-actions">
                <button type="button" class="ui-btn ui-btn-primary" data-student-dialog aria-haspopup="dialog"><x-icon name="user-plus" class="icon-18" /><span class="btn-text">+ Student</span></button>
                <button type="button" class="ui-btn" data-print-slip title="Print the day slip"><x-icon name="printer" class="icon-18" /><span class="btn-text">Day Slip</span></button>
                <details class="day-menu" data-day-menu>
                    <summary class="ui-btn ui-btn-icon" aria-label="More day actions" title="More day actions"><x-icon name="more-horizontal" class="icon-18" /></summary>
                    <div class="day-menu-list ui-popover" role="group" aria-label="Day actions">
                        <button type="button" class="ui-menu-item" data-dialog="zero-dialog"><x-icon name="check" class="icon-16" />Mark remaining as 0</button>
                        <button type="button" class="ui-menu-item ui-menu-danger" data-dialog="reset-dialog"><x-icon name="refresh-cw" class="icon-16" />Reset Day</button>
                    </div>
                </details>
            </div>

            <div class="toolbar-group toolbar-filter">
                <div class="ui-search search-input-wrap">
                    <x-icon name="search" class="icon-16" />
                    <label for="student-search" class="visually-hidden">Find student</label>
                    <input id="student-search" type="search" placeholder="Quick find student…" autocomplete="off" aria-keyshortcuts="/">
                    <kbd class="ui-kbd" aria-hidden="true">/</kbd>
                </div>
                <div class="filter-chips" role="group" aria-label="Filter students by status">
                    <button type="button" class="ui-chip" data-filter="all" aria-pressed="true">All <span class="ui-chip-count" data-count="all">{{ $counts['all'] }}</span></button>
                    <button type="button" class="ui-chip" data-filter="active" aria-pressed="false" title="Present with points above 0"><span class="chip-dot chip-dot-active" aria-hidden="true"></span>Active <span class="ui-chip-count" data-count="active">{{ $counts['active'] }}</span></button>
                    <button type="button" class="ui-chip" data-filter="zero" aria-pressed="false" title="Present with 0 points"><span class="chip-dot chip-dot-zero" aria-hidden="true"></span>Zero <span class="ui-chip-count" data-count="zero">{{ $counts['zero'] }}</span></button>
                    <button type="button" class="ui-chip" data-filter="absent" aria-pressed="false"><span class="chip-dot chip-dot-absent" aria-hidden="true"></span>Absent <span class="ui-chip-count" data-count="absent">{{ $counts['absent'] }}</span></button>
                    <button type="button" class="ui-chip" data-filter="none" aria-pressed="false"><span class="chip-dot chip-dot-none" aria-hidden="true"></span>Not recorded <span class="ui-chip-count" data-count="none">{{ $counts['none'] }}</span></button>
                </div>
            </div>
        </div>
        <span id="search-count" class="visually-hidden" aria-live="polite"></span>

        <section class="tracker-stats-banner no-print" aria-label="Day summary">
            <div class="banner-unit">
                <h2 class="banner-unit-title">{{ $currentClass->name }}</h2>
                <p class="banner-unit-desc">{{ $description !== '' ? $description : 'Daily participation for this class.' }}</p>
            </div>

            <div class="kpi-metric-box">
                <div class="ui-kpi-head"><span class="kpi-label">Total Points</span><span class="ui-icon-chip ui-icon-chip-violet"><x-icon name="activity" class="icon-16" /></span></div>
                <div class="kpi-value-row">
                    <span class="kpi-val-giant" data-summary="total">{{ $summary['total_points'] }}</span>
                    <span class="kpi-unit">pts today</span>
                </div>
            </div>

            <div class="kpi-metric-box">
                <div class="ui-kpi-head"><span class="kpi-label">Mean per present student</span><span class="ui-icon-chip ui-icon-chip-green"><x-icon name="gauge" class="icon-16" /></span></div>
                <div class="kpi-value-row">
                    <span class="kpi-val-giant emerald @if ($summary['present_recorded'] === 0) is-empty @endif" data-summary="mean">{{ $mean }}</span>
                    <span class="kpi-unit">pts/student</span>
                </div>
            </div>

            <div class="kpi-metric-box">
                <div class="ui-kpi-head"><span class="kpi-label">Present Ratio</span><span class="ui-icon-chip ui-icon-chip-blue"><x-icon name="users" class="icon-16" /></span></div>
                <div class="kpi-value-row">
                    <span class="kpi-val-giant"><span data-summary="present">{{ $summary['present_recorded'] }}</span>/<span data-summary="active">{{ $studentCount }}</span></span>
                    <span class="kpi-sub"><span class="kpi-absent"><span data-summary="absent">{{ $summary['absent'] }}</span> Absent</span> · <span data-summary="none">{{ $summary['not_recorded'] }}</span> Not recorded</span>
                </div>
            </div>
        </section>

        <p id="day-error" class="form-error tracker-error no-print" role="alert" hidden></p>

        @if ($studentCount === 0)
            <section class="tracker-empty no-print">
                <p><a href="{{ url('/classes/'.$currentClass->id.'/import') }}">Add students or import a CSV</a></p>
            </section>
        @else
            <div class="tracker-layout-grid no-print">
                <div class="cards-column">
                    <div class="cards-toolbar">
                        <button type="button" id="panel-toggle" class="btn-tag panel-toggle" aria-expanded="false" aria-controls="detail-panel">Student details</button>
                    </div>

                    <div class="cards-grid" id="card-grid">
                        @foreach ($cards as $card)
                            @include('daily.card', ['student' => $card['student'], 'entry' => $card['entry'], 'hasNote' => array_key_exists($card['student']->id, $todayNotes)])
                        @endforeach
                    </div>
                    <p id="filter-empty" class="filter-empty" hidden>No students match this filter.</p>
                </div>

                <aside id="detail-panel" class="inspector-panel" aria-label="Student details" tabindex="-1">
                    <button type="button" class="ui-btn ui-btn-sm inspector-close" data-panel-close>Close</button>
                    <p class="note-flash" data-note-flash role="status" aria-live="polite" hidden></p>
                    <div class="inspector-empty" data-panel-empty>
                        <span class="inspector-empty-icon"><x-icon name="user" class="icon-20" /></span>
                        <p class="inspector-empty-title">Select a student</p>
                        <p class="inspector-empty-hint">Tap a card to see this week’s breakdown, the cadence trend and a session note.</p>
                    </div>
                    @foreach ($cards as $card)
                        @include('daily.week-panel', ['student' => $card['student'], 'entry' => $card['entry'], 'rows' => $week[$card['student']->id], 'cadence' => $cadence[$card['student']->id] ?? [], 'noteBody' => $todayNotes[$card['student']->id] ?? '', 'recent' => $recentNotes[$card['student']->id] ?? []])
                    @endforeach
                </aside>
            </div>
        @endif

        <section class="day-slip print-only" aria-label="Day slip" data-day-slip data-note-tz="{{ config('classpulse.school_timezone', 'America/Toronto') }}">
            <dl class="day-slip-meta">
                <div><dt>Class</dt><dd>{{ $currentClass->name }}@if (filled($currentClass->title)) · {{ $currentClass->title }}@endif</dd></div>
                <div><dt>Date</dt><dd>{{ $dateText }}</dd></div>
            </dl>
            <table class="day-slip-table">
                <thead>
                    <tr><th scope="col">#</th><th scope="col">Student</th><th scope="col">Status</th><th scope="col" class="num">Points</th><th scope="col">Note</th></tr>
                </thead>
                <tbody data-slip-rows>
                    @foreach ($cards as $i => $card)
                        @php
                            $slipStatus = $card['entry']['status'];
                            $slipPoints = $card['entry']['points'];
                            $slipLabel = match (true) {
                                $slipStatus === 'absent' => 'Absent',
                                $slipStatus === 'present' && $slipPoints === 0 => 'Present · 0',
                                $slipStatus === 'present' => 'Present',
                                default => 'Not recorded',
                            };
                        @endphp
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <th scope="row">{{ $card['student']->display_name }}</th>
                            <td>{{ $slipLabel }}</td>
                            <td class="num">{{ $slipStatus === 'present' ? $slipPoints : '—' }}</td>
                            <td>{{ array_key_exists($card['student']->id, $todayNotes) ? 'Note' : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="day-slip-totals" data-slip-totals>
                <span>Present: <strong data-slip="present">{{ $summary['present_recorded'] }}</strong></span>
                <span>Absent: <strong data-slip="absent">{{ $summary['absent'] }}</strong></span>
                <span>Not recorded: <strong data-slip="none">{{ $summary['not_recorded'] }}</strong></span>
                <span>Total points: <strong data-slip="total">{{ $summary['total_points'] }}</strong></span>
            </p>
        </section>

        <dialog id="student-dialog" class="ui-dialog" aria-labelledby="student-dialog-title" @if ($errors->any()) data-open-on-load @endif>
            <form method="POST" action="{{ url('/classes/'.$currentClass->id.'/students') }}" class="student-form" novalidate>
                @csrf
                <input type="hidden" name="return_to" value="daily">
                <input type="hidden" name="date" value="{{ $date }}">
                <h2 id="student-dialog-title" class="modal-title">Add student</h2>
                <p class="modal-body-text">Adds an active student to {{ $currentClass->name }} ({{ $activeCount }}/{{ $currentClass->seatLimit() }} seats used).</p>
                @if ($errors->has('roster_cap'))
                    <p class="form-error" role="alert">{{ $errors->first('roster_cap') }}</p>
                @endif
                <div class="form-field">
                    <label for="new-student-name">Name <span class="req">(required)</span></label>
                    <input id="new-student-name" name="display_name" class="ui-input" type="text" maxlength="120" required autocomplete="off" value="{{ old('return_to') === 'daily' ? old('display_name') : '' }}" @if ($errors->has('display_name')) aria-invalid="true" aria-describedby="new-student-name-error" @endif>
                    @if ($errors->has('display_name'))<p class="form-error" id="new-student-name-error" role="alert">{{ $errors->first('display_name') }}</p>@endif
                </div>
                <div class="form-field">
                    <label for="new-student-number">Student number <span class="opt">(optional)</span></label>
                    <input id="new-student-number" name="student_number" class="ui-input" type="text" maxlength="40" autocomplete="off" value="{{ old('return_to') === 'daily' ? old('student_number') : '' }}" @if ($errors->has('student_number')) aria-invalid="true" aria-describedby="new-student-number-error" @endif>
                    @if ($errors->has('student_number'))<p class="form-error" id="new-student-number-error" role="alert">{{ $errors->first('student_number') }}</p>@endif
                </div>
                <div class="modal-actions">
                    <button type="button" class="ui-btn" data-dialog-close>Cancel</button>
                    <button type="submit" class="ui-btn ui-btn-primary">Add student</button>
                </div>
            </form>
        </dialog>

        <script src="{{ asset('js/save-queue.js') }}" defer></script>
        <script src="{{ asset('js/dialogs.js') }}" defer></script>
        <script src="{{ asset('js/daily-format.js') }}" defer></script>
        <script src="{{ asset('js/daily-notes.js') }}" defer></script>
        <script src="{{ asset('js/daily.js') }}" defer></script>

        <dialog id="reset-dialog" class="modal-box ui-dialog" aria-labelledby="reset-dialog-title" data-class-name="{{ $currentClass->name }}" data-date-text="{{ $dateText }}">
            <form method="dialog">
                <h2 id="reset-dialog-title" class="modal-title">Reset day</h2>
                <p class="modal-body-text" data-dialog-message>Reset all entries for {{ $currentClass->name }} on {{ $dateText }}? This removes {{ $entryCount }} recorded entries.</p>
                <div class="modal-actions">
                    <button type="submit" class="modal-btn modal-btn-secondary" value="cancel">Cancel</button>
                    <button type="submit" class="modal-btn modal-btn-danger" value="confirm">Reset day</button>
                </div>
            </form>
        </dialog>

        <dialog id="zero-dialog" class="modal-box ui-dialog" aria-labelledby="zero-dialog-title" data-class-name="{{ $currentClass->name }}" data-date-text="{{ $dateText }}">
            <form method="dialog">
                <h2 id="zero-dialog-title" class="modal-title">Mark remaining as 0</h2>
                <p class="modal-body-text" data-dialog-message>Record 0 for {{ $summary['not_recorded'] }} students without an entry for {{ $currentClass->name }} on {{ $dateText }}?</p>
                <div class="modal-actions">
                    <button type="submit" class="modal-btn modal-btn-secondary" value="cancel">Cancel</button>
                    <button type="submit" class="modal-btn modal-btn-primary" value="confirm">Mark remaining as 0</button>
                </div>
            </form>
        </dialog>
    </x-layouts.app>
@endif
