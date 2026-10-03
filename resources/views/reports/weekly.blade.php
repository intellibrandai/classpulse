@if ($currentClass === null)
    <x-layouts.app title="Weekly Matrix" active="weekly" :classes="$classes" :current-class="null">
        <h1 class="page-card-title">Weekly Matrix</h1>
        <section class="empty-state">
            <h2>No class yet</h2>
            <p><a class="link-accent" href="{{ url('/roster') }}">Create your first class</a> to see weekly participation.</p>
        </section>
    </x-layouts.app>
@else
    @php
        $kpis = $week['kpis'];
        $days = $week['days'];
        $weekTitle = 'Week '.$weekNumber['number'].': '.$week['range_label'];
        $classLabel = filled($currentClass->title) ? $currentClass->title : (filled($currentClass->subject_description) ? $currentClass->subject_description : null);
        $activeCount = count(array_filter($students, fn ($s) => ! $s['archived']));
        $lines = array_values(array_filter(explode("\n", $week['summary_text'])));
        $dayApi = '/api/classes/'.$currentClass->id.'/days';
    @endphp
    @push('head')
        <link rel="stylesheet" href="{{ asset('css/weekly.css') }}">
    @endpush
    <x-layouts.app
        title="Weekly Matrix"
        active="weekly"
        :classes="$classes"
        :current-class="$currentClass"
        print-title="Weekly Participation Sheet"
        :print-label="$weekTitle"
    >
        <x-slot:actions>
            <button type="button" id="undo-btn" class="btn-undo" aria-label="Undo last edit on this page" title="Undo last edit on this page" disabled><x-icon name="undo" class="icon-16" /><span class="btn-label">Undo</span></button>
        </x-slot:actions>

        <x-slot:status>
            <span id="save-pill" class="sync-badge" data-state="saved" role="status" aria-live="polite"><span class="sync-dot" aria-hidden="true"></span><span data-save-text>Saved</span></span>
        </x-slot:status>

        <x-slot:shortcuts>
            <span>Shortcuts: [/] Search · [Esc] Close editor</span>
        </x-slot:shortcuts>

        <div class="weekly-page" data-weekly data-week-api="{{ url('/api/classes/'.$currentClass->id.'/weeks/'.$week['monday']) }}" data-day-api="{{ url($dayApi) }}" data-monday="{{ $week['monday'] }}">
            <div class="wm-toolbar ui-toolbar no-print" role="toolbar" aria-label="Week tools">
                <div class="wm-nav">
                    <div class="wm-navigator">
                        <a class="btn-icon" href="{{ $previousUrl }}" aria-label="Previous week" title="Previous week"><x-icon name="chevron-left" class="icon-18" /></a>
                        <div class="wm-week-badge">
                            <x-icon name="calendar" class="wm-week-icon icon-20" />
                            <div class="wm-week-text">
                                <h1 class="wm-week-title">{{ $weekTitle }}</h1>
                                <p class="wm-term">@if (filled($currentClass->period_label)){{ $currentClass->period_label }} · @endif{{ $weekNumber['basis'] === 'semester' ? 'Semester week' : 'Calendar week' }}</p>
                            </div>
                        </div>
                        <a class="btn-icon" href="{{ $nextUrl }}" aria-label="Next week" title="Next week"><x-icon name="chevron-right" class="icon-18" /></a>
                    </div>
                    @unless ($isCurrentWeek)
                        <a class="ui-btn wm-today" href="{{ $thisWeekUrl }}">This week</a>
                    @endunless
                </div>

                <p class="wm-class-chip" title="{{ $currentClass->name }}{{ $classLabel ? ' · '.$classLabel : '' }}"><span class="wm-class-dot" aria-hidden="true"></span><strong>{{ $currentClass->name }}</strong>@if ($classLabel)<span class="wm-class-sep" aria-hidden="true">•</span><span class="wm-class-title">{{ $classLabel }}</span>@endif</p>

                <div class="wm-actions">
                    <button type="button" class="ui-btn" data-copy-summary title="Copy a plain-text summary of this week"><x-icon name="copy" class="icon-18" /><span class="btn-text" data-copy-label>Copy Summary</span></button>
                    <a class="ui-btn" href="{{ $exportUrl }}" title="Download this week as CSV"><x-icon name="download" class="icon-18" /><span class="btn-text">Export CSV</span></a>
                    <button type="button" class="ui-btn ui-btn-primary" data-print-week title="Print the weekly sheet"><x-icon name="printer" class="icon-18" /><span class="btn-text">Print Weekly Sheet</span></button>
                </div>
            </div>
            <pre class="visually-hidden" hidden data-summary-source>{{ $week['summary_text'] }}</pre>
            <p class="visually-hidden" role="status" aria-live="polite" data-copy-status></p>

            <p id="week-error" class="form-error wm-error no-print" role="alert" hidden></p>

            <section class="ui-kpi-grid wm-kpis no-print" aria-label="Week summary">
                <article class="ui-kpi glass-violet" data-kpi="average">
                    <header class="ui-kpi-head">
                        <h2 class="ui-kpi-label">Class weekly avg <x-info-tip id="tip-average" label="Class weekly avg">Total points for the week divided by the student-days marked present. Absent and not-recorded days are left out.</x-info-tip></h2>
                        <span class="ui-icon-chip ui-icon-chip-violet"><x-icon name="trending-up" class="icon-18" /></span>
                    </header>
                    <div class="wm-kpi-row">
                        <p @class(['ui-kpi-value', 'is-empty' => ! $kpis['average']['has_data']])><span data-kpi-value="average">{{ $kpis['average']['value'] }}</span> <span class="ui-kpi-unit" data-kpi-unit @if (! $kpis['average']['has_data']) hidden @endif>pts / present day</span></p>
                        <div class="ui-kpi-chart" data-kpi-chart="average">@include('reports.weekly.chart-line', ['chart' => $kpis['average']['chart']])</div>
                    </div>
                    @php $delta = $kpis['average']['delta']; @endphp
                    <p @class(['ui-kpi-sub', 'is-up' => $delta && $delta['direction'] === 'up', 'is-down' => $delta && $delta['direction'] === 'down']) data-kpi-delta @if (! $delta) hidden @endif>
                        <span data-delta-icon="up" @if (! $delta || $delta['direction'] !== 'up') hidden @endif><x-icon name="arrow-up" class="icon-16" /></span>
                        <span data-delta-icon="down" @if (! $delta || $delta['direction'] !== 'down') hidden @endif><x-icon name="arrow-down" class="icon-16" /></span>
                        <span><span data-delta-text>{{ $delta['text'] ?? '' }}</span> <span data-delta-label>{{ $delta['label'] ?? '' }}</span></span>
                    </p>
                </article>

                <article class="ui-kpi glass-blue" data-kpi="total">
                    <header class="ui-kpi-head">
                        <h2 class="ui-kpi-label">Total points <x-info-tip id="tip-total" label="Total points">The sum of the points of every present entry this week. These are participation points.</x-info-tip></h2>
                        <span class="ui-icon-chip ui-icon-chip-blue"><x-icon name="pointer" class="icon-18" /></span>
                    </header>
                    <div class="wm-kpi-row">
                        <p class="ui-kpi-value"><span data-kpi-value="total">{{ $kpis['total_points']['value'] }}</span> <span class="ui-kpi-unit">points</span></p>
                        <div class="ui-kpi-chart" data-kpi-chart="total">@include('reports.weekly.chart-bars', ['chart' => $kpis['total_points']['chart']])</div>
                    </div>
                    <p class="ui-kpi-sub" data-kpi-total-sub>{{ $kpis['total_points']['sub'] }}</p>
                </article>

                @php $att = $kpis['attendance']; @endphp
                <article class="ui-kpi glass-green" data-kpi="attendance">
                    <header class="ui-kpi-head">
                        <h2 class="ui-kpi-label">Recorded attendance rate <x-info-tip id="tip-attendance" label="Recorded attendance rate">Present sessions divided by recorded sessions (present plus absent). Days with nothing recorded are not counted; they are listed separately as not recorded.</x-info-tip></h2>
                        <span class="ui-icon-chip ui-icon-chip-green"><x-icon name="check-circle" class="icon-18" /></span>
                    </header>
                    <div class="wm-kpi-row">
                        <p @class(['ui-kpi-value', 'is-empty' => ! $att['has_data']])><span data-kpi-value="rate">{{ $att['rate_text'] }}</span></p>
                        <progress class="ui-progress ui-progress-success wm-progress" max="100" value="{{ $att['percent'] ?? 0 }}" aria-label="Recorded attendance rate" data-att-progress @if (! $att['has_data']) hidden @endif></progress>
                    </div>
                    <p class="ui-kpi-sub"><span data-att-detail>{{ $att['detail'] }}</span><span aria-hidden="true">·</span><span data-att-not>{{ $att['not_recorded_text'] }}</span></p>
                </article>

                @php $peak = $kpis['peak']; @endphp
                <article class="ui-kpi glass-violet" data-kpi="peak">
                    <header class="ui-kpi-head">
                        <h2 class="ui-kpi-label">Peak day <x-info-tip id="tip-peak" label="Peak day">The weekday with the most points this week. A tie goes to the earlier day. It shows No data until something is recorded.</x-info-tip></h2>
                        <span class="ui-icon-chip ui-icon-chip-violet"><x-icon name="zap" class="icon-18" /></span>
                    </header>
                    <p @class(['ui-kpi-value', 'wm-peak-value', 'is-empty' => $peak === null])><span data-peak-day>{{ $peak['weekday'] ?? 'No data' }}</span> <span class="ui-badge ui-badge-present" data-peak-points @if ($peak === null) hidden @endif>{{ $peak['points_text'] ?? '' }}</span></p>
                    <p class="ui-kpi-sub" data-peak-sub @if ($peak === null) hidden @endif>{{ $peak['present_text'] ?? '' }}</p>
                </article>
            </section>

            <section class="wm-info-strip glass no-print" aria-label="How to read the matrix">
                <span class="ui-icon-chip"><x-icon name="info" class="icon-18" /></span>
                <p class="wm-info-text"><strong>Good to know:</strong> Absent sessions (<span class="legend-chip heat-absent" aria-hidden="true">A</span><span class="visually-hidden">A</span>) and not-recorded days are left out of every average. Select a cell to change its points or attendance.</p>
                <div class="wm-scale">
                    <span class="ui-eyebrow">Scale</span>
                    <ul class="matrix-legend" aria-label="Legend">
                        <li class="legend-item"><span class="legend-chip heat-0" aria-hidden="true">0</span> 0</li>
                        <li class="legend-item"><span class="legend-chip heat-low" aria-hidden="true">2</span> 1–2</li>
                        <li class="legend-item"><span class="legend-chip heat-mid" aria-hidden="true">4</span> 3–5</li>
                        <li class="legend-item"><span class="legend-chip heat-high" aria-hidden="true">6</span> 6+</li>
                        <li class="legend-item"><span class="legend-chip heat-absent" aria-hidden="true">A</span> Absent</li>
                        <li class="legend-item"><span class="legend-chip is-empty" aria-hidden="true">—</span> Not recorded</li>
                    </ul>
                </div>
            </section>

            <div class="wm-filters no-print">
                <div class="ui-search wm-search">
                    <x-icon name="search" class="icon-16" />
                    <label for="matrix-search" class="visually-hidden">Filter by student name</label>
                    <input id="matrix-search" type="search" placeholder="Filter by student name…" autocomplete="off" aria-keyshortcuts="/" data-matrix-search>
                    <kbd class="ui-kbd" aria-hidden="true">/</kbd>
                </div>
                <div class="wm-sort">
                    <label for="matrix-sort" class="ui-eyebrow">Sort</label>
                    <select id="matrix-sort" class="ui-input" data-matrix-sort>
                        <option value="name-asc">Name (A–Z)</option>
                        <option value="name-desc">Name (Z–A)</option>
                        <option value="total-desc">Weekly total (high to low)</option>
                        <option value="avg-desc">Weekly avg (high to low)</option>
                        <option value="absences-desc">Absences (most first)</option>
                    </select>
                </div>
                <p class="wm-count ui-badge" id="matrix-count" role="status" aria-live="polite" data-matrix-count data-active-total="{{ $activeCount }}"><x-icon name="users" class="icon-16" /><span data-count-text>Showing <strong>{{ $activeCount }}</strong> active {{ $activeCount === 1 ? 'student' : 'students' }}</span></p>
            </div>

            @if ($students === [])
                <section class="empty-state">
                    <h2>No students in this class</h2>
                    <p><a class="link-accent" href="{{ url('/classes/'.$currentClass->id.'/import') }}">Add students or import a CSV</a> to see weekly participation.</p>
                </section>
            @else
                <p class="scroll-hint no-print">Scroll sideways inside the table to see every column</p>
                <div class="wm-scroll glass" role="region" aria-label="Weekly participation matrix" tabindex="0" data-matrix-scroll>
                    <table class="wm-table" data-matrix>
                        <caption class="visually-hidden">Points per student and day for {{ $weekTitle }}. Select a cell to edit it.</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="wm-col-student">Student name</th>
                                @foreach ($days as $day)
                                    <th scope="col" @class(['wm-col-day', 'is-today' => $day['today']])><span class="wm-dow">{{ $day['weekday'] }}</span><span class="wm-date">{{ $day['label'] }}</span></th>
                                @endforeach
                                <th scope="col" class="wm-col-stat"><span class="wm-dow">Weekly Total</span><span class="wm-date">Sum of points</span></th>
                                <th scope="col" class="wm-col-stat"><span class="wm-dow">Weekly Avg</span><span class="wm-date">Present days only</span></th>
                                <th scope="col" class="wm-col-stat"><span class="wm-dow">Absences</span><span class="wm-date">This week</span></th>
                            </tr>
                        </thead>
                        <tbody data-matrix-body>
                            @foreach ($students as $i => $student)
                                @php $row = $week['students'][$i]; @endphp
                                <tr data-student-row data-student-id="{{ $student['id'] }}" data-name="{{ mb_strtolower($student['name']) }}" data-order="{{ $i }}" data-archived="{{ $student['archived'] ? '1' : '0' }}" data-total="{{ $row['total_points'] }}" data-avg="{{ $row['average'] }}" data-absences="{{ $row['absences'] }}">
                                    <th scope="row" class="wm-student">
                                        <span class="ui-avatar" aria-hidden="true">{{ \App\Support\Initials::of($student['name']) }}</span>
                                        <span class="wm-student-text">
                                            <a class="link-plain wm-name" href="{{ url('/students/'.$student['id']) }}" data-student-name>{{ $student['name'] }}</a>
                                            @if ($student['archived'])<span class="ui-badge ui-badge-none wm-archived">Archived</span>@endif
                                            @if (filled($student['student_number']))<span class="wm-number">ID: {{ $student['student_number'] }}</span>@endif
                                        </span>
                                    </th>
                                    @foreach ($row['cells'] as $k => $cell)
                                        <td class="wm-day">@include('reports.weekly.cell', ['cell' => $cell, 'day' => $days[$k], 'name' => $student['name'], 'studentId' => $student['id'], 'archived' => $student['archived']])</td>
                                    @endforeach
                                    <td class="wm-stat wm-total" data-row-total>{{ $row['total_text'] }}</td>
                                    <td class="wm-stat"><span class="wm-avg-pill" data-row-avg>{{ $row['average_text'] }}</span><span class="wm-sub" data-row-avg-sub>{{ $row['average_sub'] }}</span></td>
                                    <td class="wm-stat"><span @class(['ui-badge', 'ui-badge-absent' => $row['absences'] > 0, 'ui-badge-zero' => $row['absences'] === 0]) data-row-absences>{{ $row['absences_text'] }}</span></td>
                                </tr>
                            @endforeach
                            <tr data-no-match hidden><td colspan="{{ count($days) + 4 }}" class="row-empty">No students match this search.</td></tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row" colspan="{{ count($days) + 1 }}" class="wm-foot-label">Class totals</th>
                                <td class="wm-foot"><strong data-foot="total">{{ $week['footer']['total_points'] }}</strong><span>Total points</span></td>
                                <td class="wm-foot wm-foot-avg"><strong data-foot="average">{{ $week['footer']['average_text'] }}</strong><span>Class avg</span></td>
                                <td class="wm-foot"><strong data-foot="absences">{{ $week['footer']['absences'] }}</strong><span>Total absences</span></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif

            <section class="wm-print-summary print-only" aria-label="Week summary">
                <ul data-print-summary>
                    @foreach (array_slice($lines, 1) as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
                <p>Legend: A = absent · — = not recorded · absent and not-recorded days are left out of every average.</p>
            </section>

            <div class="ui-popover glass-strong cell-editor no-print" id="cell-editor" role="dialog" aria-modal="true" aria-labelledby="cell-editor-title" hidden data-cell-editor>
                <div class="cell-editor-head">
                    <p class="cell-editor-title" id="cell-editor-title" data-editor-title>Edit</p>
                    <button type="button" class="ui-btn ui-btn-ghost ui-btn-icon ui-btn-sm" data-editor-close aria-label="Close editor"><x-icon name="x" class="icon-18" /></button>
                </div>
                <p class="cell-editor-state" data-editor-state></p>
                <div class="cell-editor-points" role="group" aria-label="Points">
                    <button type="button" class="ui-btn ui-btn-icon cell-editor-step" data-editor-action="decrement" aria-label="Subtract 1 point"><x-icon name="minus" class="icon-18" /></button>
                    <input type="number" class="ui-input cell-editor-input" min="0" max="99" step="1" inputmode="numeric" data-editor-input aria-label="Points, 0 to 99" placeholder="—">
                    <button type="button" class="ui-btn ui-btn-icon cell-editor-step" data-editor-action="increment" aria-label="Add 1 point"><x-icon name="plus" class="icon-18" /></button>
                    <button type="button" class="ui-btn ui-btn-primary" data-editor-action="set_points">Set</button>
                </div>
                <div class="cell-editor-actions">
                    <button type="button" class="ui-btn ui-btn-sm" data-editor-action="set_zero">Record 0</button>
                    <button type="button" class="ui-btn ui-btn-sm" data-editor-action="absent_on"><x-icon name="x" class="icon-16" />Mark Absent</button>
                    <button type="button" class="ui-btn ui-btn-sm" data-editor-action="absent_off"><x-icon name="check" class="icon-16" />Mark present</button>
                    <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" data-editor-action="clear">Clear</button>
                </div>
                <p class="cell-editor-error" role="alert" data-editor-error hidden></p>
            </div>
        </div>

        <script src="{{ asset('js/save-queue.js') }}" defer></script>
        <script src="{{ asset('js/weekly-lib.js') }}" defer></script>
        <script src="{{ asset('js/weekly.js') }}" defer></script>
    </x-layouts.app>
@endif
