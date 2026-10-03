@if ($currentClass === null)
    <x-layouts.app title="Semester Analytics" active="semester" :classes="$classes" :current-class="null">
        <h1 class="page-card-title">Semester Analytics</h1>
        <section class="empty-state">
            <h2>No class yet</h2>
            <p><a class="link-accent" href="{{ url('/roster') }}">Create your first class</a> to see semester analytics.</p>
        </section>
    </x-layouts.app>
@else
    @php
        $period = $semester['period'];
        $kpis = $semester['kpis'];
        $rows = $semester['rows'];
        $hasTrend = $semester['has_trend'];
        $classLabel = filled($currentClass->title) ? $currentClass->title : (filled($currentClass->subject_description) ? $currentClass->subject_description : null);
        $printLabel = $period['label'].' · '.$period['range_label'];
        $colCount = $hasTrend ? 8 : 7;
        $settingsLabel = $semester['has_quarters'] ? 'Edit period dates' : 'Set period dates';
        $periodUrl = fn (string $key) => $pageUrl.($key === 'full' ? '' : '&period='.$key);
    @endphp
    @push('head')
        <link rel="stylesheet" href="{{ asset('css/semester.css') }}">
    @endpush
    <x-layouts.app
        title="Semester Analytics"
        active="semester"
        :classes="$classes"
        :current-class="$currentClass"
        print-title="Semester Summary"
        :print-label="$printLabel"
    >
        <x-slot:status>
            <span id="save-pill" class="sync-badge" data-state="saved" role="status" aria-live="polite"><span class="sync-dot" aria-hidden="true"></span><span data-save-text>Saved</span></span>
        </x-slot:status>

        <x-slot:shortcuts>
            <span>Shortcuts: [/] Search · [Esc] Deselect student</span>
        </x-slot:shortcuts>

        <div
            class="semester-page"
            data-semester
            data-student-api="{{ $studentApi }}"
            data-note-api="{{ $noteApi }}"
            data-comments-api="{{ $commentsApi }}"
            data-comment-api="{{ $commentApi }}"
            data-period="{{ $period['key'] }}"
            data-period-label="{{ $period['label'] }}"
            data-period-from="{{ $period['from'] }}"
            data-period-to="{{ $period['to'] }}"
            data-today="{{ $today }}"
            data-page-size="{{ $semester['page_size'] }}"
        >
            <h1 class="visually-hidden">Semester Analytics</h1>

            <div class="sem-toolbar ui-toolbar no-print" role="toolbar" aria-label="Semester tools">
                <div class="sem-course"><x-class-menu :classes="$classes" :current="$currentClass" variant="chip" :base-url="url('/semester')" /></div>

                <div class="sem-period-box">
                    <x-icon name="calendar" class="sem-period-icon icon-20" />
                    <div class="sem-period-text">
                        <p class="ui-eyebrow">Academic period</p>
                        <p class="sem-period-value"><strong>{{ $period['label'] }}</strong> <span>{{ $period['range_label'] }}</span></p>
                    </div>
                    <a class="sem-period-link" href="{{ $settingsUrl }}">{{ $settingsLabel }}</a>
                </div>

                {{-- The actions come before the period control in the source so the tab order matches the visual order (the period row is ordered last by CSS). --}}
                <div class="sem-actions">
                    <button type="button" class="ui-btn" data-open-comments aria-haspopup="dialog" title="Compose report card comments from saved notes"><x-icon name="message-square" class="icon-18" /><span class="btn-text">Report Card Comments</span></button>
                    <a class="ui-btn" href="{{ $exportUrl }}" title="Download the selected period as CSV"><x-icon name="file-text" class="icon-18" /><span class="btn-text">Gradebook CSV</span></a>
                    <button type="button" class="ui-btn ui-btn-primary" data-print-summary title="Print this summary"><x-icon name="printer" class="icon-18" /><span class="btn-text">Print Summary</span></button>
                </div>
                <div class="sem-periods">
                    <p class="ui-eyebrow">Reporting period</p>
                    <div class="ui-segmented" role="group" aria-label="Period">
                        @foreach ($semester['periods'] as $p)
                            <a class="ui-segmented-item" href="{{ $periodUrl($p['key']) }}" title="{{ $p['range_label'] }}" @if ($p['selected']) aria-current="page" @endif>{{ $p['key'] === 'full' ? 'Full Semester' : $p['label'] }}</a>
                        @endforeach
                    </div>
                    @unless ($semester['has_quarters'])
                        <p class="sem-hint">Q1 and Q2 are not set for this class. <a class="link-accent" href="{{ $settingsUrl }}">Set period dates</a></p>
                    @endunless
                </div>
            </div>

            <section class="ui-kpi-grid sem-kpis no-print" aria-label="Semester summary">
                <article class="ui-kpi glass-blue" data-kpi="total">
                    <header class="ui-kpi-head">
                        <h2 class="ui-kpi-label">Total points <x-info-tip id="tip-total" label="Total points">The sum of the points of every present entry in this period. These are participation points.</x-info-tip></h2>
                        <span class="ui-icon-chip ui-icon-chip-blue"><x-icon name="pointer" class="icon-18" /></span>
                    </header>
                    <div class="sem-kpi-row">
                        <p class="ui-kpi-value"><span>{{ $kpis['total_points']['value'] }}</span> <span class="ui-kpi-unit">points</span></p>
                        <div class="ui-kpi-chart">@include('reports.weekly.chart-bars', ['chart' => $kpis['total_points']['chart']])</div>
                    </div>
                    <p class="ui-kpi-sub">{{ $kpis['total_points']['sub'] }}</p>
                </article>

                <article class="ui-kpi glass-green" data-kpi="mean">
                    <header class="ui-kpi-head">
                        <h2 class="ui-kpi-label">Mean per present day <x-info-tip id="tip-mean" label="Mean per present day">Total points divided by the present days recorded. Absent and not-recorded days are left out. It shows No data until a present day is recorded.</x-info-tip></h2>
                        <span class="ui-icon-chip ui-icon-chip-green"><x-icon name="gauge" class="icon-18" /></span>
                    </header>
                    <div class="sem-kpi-row">
                        <p @class(['ui-kpi-value', 'is-empty' => ! $kpis['mean']['has_data']])><span>{{ $kpis['mean']['value'] }}</span> @if ($kpis['mean']['has_data'])<span class="ui-kpi-unit">pts / present day</span>@endif</p>
                        <div class="ui-kpi-chart">@include('reports.weekly.chart-line', ['chart' => $kpis['mean']['chart']])</div>
                    </div>
                    @if ($kpis['mean']['delta'])
                        @php $d = $kpis['mean']['delta']; @endphp
                        <p @class(['ui-kpi-sub', 'is-up' => $d['direction'] === 'up', 'is-down' => $d['direction'] === 'down'])>
                            @if ($d['direction'] === 'up')<x-icon name="arrow-up" class="icon-16" />@elseif ($d['direction'] === 'down')<x-icon name="arrow-down" class="icon-16" />@endif
                            <span>{{ $d['text'] }} {{ $d['label'] }}</span>
                        </p>
                    @else
                        <p class="ui-kpi-sub">Weekly average across the period</p>
                    @endif
                </article>

                <article class="ui-kpi glass-violet" data-kpi="coverage">
                    <header class="ui-kpi-head">
                        <h2 class="ui-kpi-label">Session coverage <x-info-tip id="tip-coverage" label="Session coverage">School days with at least one entry, out of the school days (Monday to Friday) that have passed in this period. There is no holiday calendar.</x-info-tip></h2>
                        <span class="ui-icon-chip"><x-icon name="calendar-check" class="icon-18" /></span>
                    </header>
                    <div class="sem-kpi-row">
                        <p @class(['ui-kpi-value', 'is-empty' => ! $kpis['coverage']['has_data']])><span>{{ $kpis['coverage']['value'] }}</span> <span class="ui-kpi-unit">{{ $kpis['coverage']['unit'] }}</span></p>
                    </div>
                    @if ($kpis['coverage']['percent'] !== null)
                        <progress class="ui-progress sem-progress" max="100" value="{{ $kpis['coverage']['percent'] }}" aria-label="School days with records"></progress>
                    @endif
                    <p class="ui-kpi-sub">{{ $kpis['coverage']['sub'] }}</p>
                </article>

                @php $att = $kpis['attendance']; @endphp
                <article class="ui-kpi glass-green" data-kpi="attendance">
                    <header class="ui-kpi-head">
                        <h2 class="ui-kpi-label">Attendance health <x-info-tip id="tip-attendance" label="Attendance health">Present entries divided by recorded entries (present plus absent). Days with nothing recorded are not counted: they are listed as not recorded (active students times school days, minus recorded entries).</x-info-tip></h2>
                        <span class="ui-icon-chip ui-icon-chip-violet"><x-icon name="users" class="icon-18" /></span>
                    </header>
                    <div class="sem-kpi-row">
                        <p @class(['ui-kpi-value', 'is-empty' => ! $att['has_data']])><span>{{ $att['rate_text'] }}</span> @if ($att['has_data'])<span class="ui-kpi-unit">recorded rate</span>@endif</p>
                        @if ($att['percent'] !== null)
                            <progress class="ui-progress ui-progress-success sem-progress" max="100" value="{{ $att['percent'] }}" aria-label="Recorded attendance rate"></progress>
                        @endif
                    </div>
                    <p class="ui-kpi-sub"><strong class="sem-absences">{{ $att['absences_text'] }}</strong><span aria-hidden="true">·</span><span>{{ $att['not_recorded_text'] }}</span></p>
                    @if ($att['delta'])
                        @php $d = $att['delta']; @endphp
                        <p @class(['ui-kpi-sub', 'is-up' => $d['direction'] === 'up', 'is-down' => $d['direction'] === 'down'])>
                            @if ($d['direction'] === 'up')<x-icon name="arrow-up" class="icon-16" />@elseif ($d['direction'] === 'down')<x-icon name="arrow-down" class="icon-16" />@endif
                            <span>{{ $d['text'] }} {{ $d['label'] }}</span>
                        </p>
                    @endif
                </article>
            </section>

            <section class="sem-print-kpis print-only" aria-label="Period summary">
                <p class="sem-print-period"><strong>{{ $period['label'] }}</strong> · {{ $period['range_label'] }}</p>
                <ul>
                    @foreach ($semester['print_lines'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </section>

            @if ($rows === [])
                <section class="empty-state">
                    <h2>No students in this class</h2>
                    <p><a class="link-accent" href="{{ url('/classes/'.$currentClass->id.'/import') }}">Add students or import a CSV</a> to see semester analytics.</p>
                </section>
            @else
                @unless ($semester['has_data'])
                    <p class="empty-state sem-no-data">No data: nothing has been recorded in {{ $period['label'] }} yet.</p>
                @endunless

                <div class="sem-layout">
                    <section class="sem-master glass" aria-labelledby="sem-master-title">
                        <header class="sem-master-head">
                            <div class="sem-master-title">
                                <h2 id="sem-master-title">Master Cumulative Roster</h2>
                                <span class="ui-badge ui-badge-present sem-enrolled">{{ $semester['enrolled'] }} Enrolled</span>
                            </div>
                            <p class="sem-master-desc">Totals, attendance and averages for {{ $period['label'] }}. Absent and not-recorded days are left out of every average.</p>
                        </header>

                        <div class="sem-filters no-print">
                            <div class="ui-search sem-search">
                                <x-icon name="search" class="icon-16" />
                                <label for="sem-search" class="visually-hidden">Search by student name or number</label>
                                <input id="sem-search" type="search" placeholder="Search name or number…" autocomplete="off" aria-keyshortcuts="/" data-search>
                                <kbd class="ui-kbd" aria-hidden="true">/</kbd>
                            </div>
                            <div class="sem-sort">
                                <label for="sem-sort" class="ui-eyebrow">Sort</label>
                                <select id="sem-sort" class="ui-input" data-sort>
                                    <option value="name-asc">Name (A–Z)</option>
                                    <option value="total-desc">Total points (high to low)</option>
                                    <option value="avg-desc">Mean per present day (high to low)</option>
                                    <option value="absences-desc">Absences (most first)</option>
                                    <option value="present-desc">Present days (most first)</option>
                                </select>
                            </div>
                            <button type="button" class="ui-btn sem-inspector-toggle" id="sem-inspector-toggle" aria-expanded="false" aria-controls="sem-inspector" data-inspector-toggle><x-icon name="eye" class="icon-18" />Student details</button>
                        </div>

                        <p class="scroll-hint no-print">Scroll sideways inside the table to see every column</p>
                        <div class="sem-scroll" role="region" aria-label="Master Cumulative Roster table" tabindex="0" data-scroll>
                            <table class="sem-table" data-table>
                                <caption class="visually-hidden">Semester totals per student for {{ $period['label'] }}, {{ $period['range_label'] }}. Use Inspect to open a student in the inspector.</caption>
                                <thead>
                                    <tr>
                                        <th scope="col" class="sem-col-rank" data-rank-head hidden><span data-rank-label>Rank</span></th>
                                        <th scope="col" class="sem-col-student">Student</th>
                                        <th scope="col" class="num">Total points</th>
                                        <th scope="col" class="num">Present / recorded days</th>
                                        <th scope="col" class="num">Absences</th>
                                        <th scope="col" class="num">Semester avg</th>
                                        @if ($hasTrend)
                                            <th scope="col" class="num">{{ $semester['trend_header'] }}</th>
                                        @endif
                                        <th scope="col" class="sem-col-action no-print"><span class="visually-hidden">Action</span></th>
                                    </tr>
                                </thead>
                                <tbody data-body>
                                    @foreach ($rows as $i => $row)
                                        <tr
                                            data-row
                                            data-student-id="{{ $row['id'] }}"
                                            data-search="{{ mb_strtolower($row['name'].' '.($row['preferred_name'] ?? '').' '.($row['student_number'] ?? '')) }}"
                                            data-order="{{ $i }}"
                                            data-total="{{ $row['total_points'] }}"
                                            data-present="{{ $row['present_days'] }}"
                                            data-absences="{{ $row['absences'] }}"
                                            data-avg="{{ $row['average'] }}"
                                        >
                                            <td class="sem-col-rank num" data-rank-cell hidden></td>
                                            <th scope="row" class="sem-student">
                                                <span class="ui-avatar" aria-hidden="true">{{ $row['initials'] }}</span>
                                                <span class="sem-student-text">
                                                    <a class="link-plain sem-name" href="{{ url('/students/'.$row['id']) }}" data-student-name>{{ $row['name'] }}</a>
                                                    @if ($row['preferred_name'])<span class="sem-meta">Preferred: {{ $row['preferred_name'] }}</span>@endif
                                                    <span class="sem-meta">@if ($row['student_number'])ID: {{ $row['student_number'] }}@endif @if ($row['archived'])<span class="ui-badge ui-badge-none sem-archived">Archived</span>@endif</span>
                                                </span>
                                            </th>
                                            <td class="num sem-total"><strong>{{ $row['total_points'] }}</strong> <span class="sem-unit">pts</span></td>
                                            <td class="num"><strong>{{ $row['present_days'] }}</strong><span class="sem-unit"> / {{ $row['days_recorded'] }}</span></td>
                                            <td class="num"><span @class(['ui-badge', 'ui-badge-absent' => $row['absences'] > 0, 'ui-badge-zero' => $row['absences'] === 0])>{{ $row['absences'] }}</span></td>
                                            <td class="num"><span @class(['sem-avg', 'is-empty' => $row['average'] === null])>{{ $row['average_text'] }}</span>@if ($row['average'] !== null)<span class="sem-unit"> /day</span>@endif</td>
                                            @if ($hasTrend)
                                                <td class="num">
                                                    @if ($row['trend'])
                                                        <span @class(['ui-badge', 'ui-badge-present' => $row['trend']['direction'] === 'up', 'ui-badge-absent' => $row['trend']['direction'] === 'down'])>
                                                            @if ($row['trend']['direction'] === 'up')<x-icon name="arrow-up" class="icon-16" />@elseif ($row['trend']['direction'] === 'down')<x-icon name="arrow-down" class="icon-16" />@endif
                                                            {{ $row['trend']['text'] }}
                                                        </span>
                                                    @else
                                                        <span class="sem-unit" title="No comparable data in both periods">—</span>
                                                    @endif
                                                </td>
                                            @endif
                                            <td class="sem-col-action no-print"><button type="button" class="ui-btn ui-btn-sm sem-inspect" data-inspect aria-pressed="false" aria-label="Inspect {{ $row['name'] }}"><span data-inspect-text>Inspect</span></button></td>
                                        </tr>
                                    @endforeach
                                    <tr data-no-match hidden><td colspan="{{ $colCount + 1 }}" class="row-empty">No students match this search.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <footer class="sem-master-foot no-print">
                            <p class="sem-showing" role="status" aria-live="polite" data-showing>Showing {{ min(count($rows), $semester['page_size']) }} of {{ count($rows) }} students</p>
                            <button type="button" class="ui-btn sem-more" data-more hidden>Show more</button>
                        </footer>
                    </section>

                    <aside class="sem-inspector glass" id="sem-inspector" aria-label="Student inspector" tabindex="-1" data-inspector>
                        <button type="button" class="ui-btn ui-btn-sm sem-inspector-close" data-inspector-close>Close</button>

                        <p class="ui-eyebrow sem-inspector-eyebrow">Student inspector</p>
                        <div class="sem-inspector-empty" data-inspector-empty>
                            <span class="ui-icon-chip"><x-icon name="users" class="icon-20" /></span>
                            <p class="sem-empty-title">No student selected</p>
                            <p class="sem-empty-hint">Choose Inspect on a row to see that student's weekly evolution, audit log and notes for {{ $period['label'] }}.</p>
                            <ul class="sem-empty-tags" aria-hidden="true">
                                <li class="ui-badge ui-badge-violet">Weekly evolution</li>
                                <li class="ui-badge ui-badge-info">Audit log</li>
                                <li class="ui-badge ui-badge-present">Notes</li>
                            </ul>
                        </div>

                        <p class="sem-inspector-status" role="status" aria-live="polite" data-inspector-status hidden></p>

                        <div class="sem-inspector-body" data-inspector-body hidden>
                            <header class="sem-student-head">
                                <span class="ui-avatar sem-student-avatar" aria-hidden="true" data-f="initials"></span>
                                <div class="sem-student-id">
                                    <h2 class="sem-student-name" data-f="name"></h2>
                                    <p class="sem-meta" data-f="preferred" hidden></p>
                                    <p class="sem-meta" data-f="number" hidden></p>
                                    <p class="sem-today"><span class="ui-badge" data-f="today"></span></p>
                                </div>
                            </header>

                            <dl class="sem-stats">
                                <div class="sem-stat"><dt>Total points</dt><dd data-f="total"></dd></div>
                                <div class="sem-stat"><dt>Present days</dt><dd data-f="present"></dd><p data-f="present-sub"></p></div>
                                <div class="sem-stat"><dt>Absences</dt><dd data-f="absences"></dd></div>
                                <div class="sem-stat"><dt>Semester average</dt><dd data-f="average"></dd><p data-f="average-sub">per present day</p></div>
                            </dl>

                            <section class="sem-section" aria-labelledby="sem-chart-title">
                                <h3 class="sem-section-title" id="sem-chart-title"><x-icon name="chart-line" class="icon-16" />Weekly evolution</h3>
                                <div class="sem-chart" data-chart></div>
                                <p class="sem-chart-empty" data-chart-empty hidden></p>
                                <p class="sem-chart-summary" data-chart-summary hidden><span data-f="lowest"></span><span data-f="peak"></span></p>
                                <p class="visually-hidden" data-chart-alt></p>
                            </section>

                            <section class="sem-section" aria-labelledby="sem-log-title">
                                <h3 class="sem-section-title" id="sem-log-title"><x-icon name="list" class="icon-16" />Chronological audit log</h3>
                                <ul class="sem-log" data-log></ul>
                                <p class="sem-log-empty" data-log-empty hidden>No recorded days in this period.</p>
                                <button type="button" class="ui-btn ui-btn-sm sem-log-more" data-log-more hidden>Show more</button>
                            </section>

                            <section class="sem-section" aria-labelledby="sem-notes-title">
                                <div class="sem-section-row">
                                    <h3 class="sem-section-title" id="sem-notes-title"><x-icon name="message-square" class="icon-16" />Instructional qualitative log</h3>
                                    <button type="button" class="ui-btn ui-btn-sm" data-note-add aria-controls="sem-note-form"><x-icon name="plus" class="icon-16" />Add note</button>
                                </div>
                                <form class="sem-note-form" id="sem-note-form" data-note-form hidden novalidate>
                                    <p class="sem-note-form-title" data-note-form-title>Add note</p>
                                    <label for="sem-note-date" class="ui-eyebrow">Date</label>
                                    <input type="date" id="sem-note-date" class="ui-input" data-note-date required>
                                    <p class="sem-hint" data-note-replace hidden>A note already exists for this date. Saving replaces it.</p>
                                    <label for="sem-note-body" class="ui-eyebrow">Note</label>
                                    <textarea id="sem-note-body" class="ui-input sem-note-input" rows="4" data-note-body aria-describedby="sem-note-count"></textarea>
                                    <div class="sem-note-meta">
                                        <span class="sem-note-count" id="sem-note-count" data-note-count data-level="ok">0 / 2000</span>
                                        <span class="sem-note-status" role="status" aria-live="polite" data-note-status></span>
                                    </div>
                                    <p class="form-error" role="alert" data-note-error hidden></p>
                                    <div class="sem-note-buttons">
                                        <button type="submit" class="ui-btn ui-btn-primary" data-note-save>Save note</button>
                                        <button type="button" class="ui-btn" data-note-cancel>Cancel</button>
                                    </div>
                                </form>
                                <ul class="sem-notes" data-notes></ul>
                                <p class="sem-notes-empty" data-notes-empty hidden>No notes saved for this student yet.</p>
                            </section>
                        </div>
                    </aside>
                </div>
            @endif

            <section class="sem-print-log print-only" aria-label="Selected student log" data-print-log></section>

            <dialog class="ui-dialog sem-comments" id="sem-comments" aria-labelledby="sem-comments-title" data-comments-dialog>
                <header class="sem-dialog-head">
                    <div>
                        <h2 id="sem-comments-title">Report Card Comments</h2>
                        <p class="sem-dialog-period">{{ $period['label'] }} · {{ $period['range_label'] }}</p>
                    </div>
                    <form method="dialog"><button class="ui-btn ui-btn-ghost ui-btn-icon" aria-label="Close report card comments"><x-icon name="x" class="icon-18" /></button></form>
                </header>
                <p class="sem-dialog-note">Each draft starts from the real numbers of this period and your saved notes. You write the final wording: edit it freely, then copy it. Your edits are saved automatically as a draft for this class and period, so they are here next time. Nothing is sent anywhere.</p>
                <div class="sem-comments-list" data-comments-list>
                    @forelse ($rows as $row)
                        <article
                            class="sem-comment"
                            data-comment
                            data-student-id="{{ $row['id'] }}"
                            data-name="{{ $row['name'] }}"
                            data-preferred="{{ $row['preferred_name'] }}"
                            data-total="{{ $row['total_points'] }}"
                            data-present="{{ $row['present_days'] }}"
                            data-average="{{ $row['average'] === null ? '' : $row['average_text'] }}"
                        >
                            <h3 class="sem-comment-name">{{ $row['name'] }}@if ($row['archived']) <span class="ui-badge ui-badge-none">Archived</span>@endif</h3>
                            <p class="sem-comment-stats">{{ $row['total_points'] }} points · {{ $row['present_days'] }} present {{ $row['present_days'] === 1 ? 'day' : 'days' }} · average {{ $row['average_text'] }} · {{ $row['absences'] }} {{ $row['absences'] === 1 ? 'absence' : 'absences' }}</p>
                            <div class="sem-comment-notes">
                                <p class="ui-eyebrow">Saved notes in this period</p>
                                <ul data-comment-notes>
                                    @foreach ($row['notes'] as $note)
                                        <li data-note-date="{{ $note['date'] }}" data-note-label="{{ $note['label'] }}"><time datetime="{{ $note['date'] }}">{{ $note['label'] }}</time><span data-note-body>{{ $note['body'] }}</span></li>
                                    @endforeach
                                </ul>
                                <p class="sem-comment-none" data-comment-none @if ($row['notes'] !== []) hidden @endif>No saved notes in this period.</p>
                            </div>
                            <div class="sem-comment-label">
                                <label class="ui-eyebrow" for="comment-{{ $row['id'] }}">Comment draft for {{ $row['name'] }}</label>
                                <span class="ui-badge ui-badge-none sem-comment-badge" data-comment-badge>Template</span>
                            </div>
                            <textarea id="comment-{{ $row['id'] }}" class="ui-input sem-comment-input" rows="4" maxlength="4000" data-comment-input></textarea>
                            <div class="sem-comment-foot">
                                <span class="sem-comment-state" role="status" aria-live="polite" data-comment-state></span>
                                <button type="button" class="ui-btn ui-btn-sm sem-comment-retry" data-comment-retry hidden>Retry</button>
                                <button type="button" class="ui-btn ui-btn-sm" data-comment-reset aria-label="Reset to template for {{ $row['name'] }}"><x-icon name="undo" class="icon-16" /><span>Reset to template</span></button>
                                <button type="button" class="ui-btn ui-btn-sm" data-comment-copy aria-label="Copy comment for {{ $row['name'] }}"><x-icon name="copy" class="icon-16" /><span data-copy-text>Copy comment</span></button>
                            </div>
                        </article>
                    @empty
                        <p class="sem-comment-none">No students in this class.</p>
                    @endforelse
                </div>
                <footer class="sem-dialog-foot">
                    <p class="sem-copy-status" role="status" aria-live="polite" data-comments-status></p>
                    <button type="button" class="ui-btn" data-comments-copy-all @disabled($rows === [])><x-icon name="copy" class="icon-16" />Copy all</button>
                    <form method="dialog"><button class="ui-btn ui-btn-primary">Close</button></form>
                </footer>
            </dialog>

            <dialog class="ui-dialog" id="sem-delete-dialog" aria-labelledby="sem-delete-title" data-delete-dialog>
                <form method="dialog">
                    <h2 id="sem-delete-title" class="sem-delete-title">Delete this note?</h2>
                    <p class="sem-delete-text" data-delete-text></p>
                    <div class="sem-dialog-buttons">
                        <button value="cancel" class="ui-btn">Keep note</button>
                        <button value="confirm" class="ui-btn ui-btn-danger">Delete note</button>
                    </div>
                </form>
            </dialog>
        </div>

        <script src="{{ asset('js/dialogs.js') }}" defer></script>
        <script src="{{ asset('js/daily-notes.js') }}" defer></script>
        <script src="{{ asset('js/semester-lib.js') }}" defer></script>
        <script src="{{ asset('js/semester.js') }}" defer></script>
    </x-layouts.app>
@endif
