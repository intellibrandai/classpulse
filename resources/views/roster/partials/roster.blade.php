@php
    $active = count($rosterRows);
    $cap = $currentClass->seatLimit();
    $percent = $cap > 0 ? (int) round($active / $cap * 100) : 0;
    $full = $active >= $cap;
    $warn = $cap > 0 && $active / $cap >= 0.9;
    $quick = $scope === 'quick';
@endphp
<section class="ro-card ro-roster glass glass-blue" aria-labelledby="roster-title">
    <header class="ro-roster-head">
        <div class="ro-roster-titles">
            <h2 id="roster-title" class="ro-card-title ro-roster-title">Class Roster <span class="ui-badge ui-badge-violet" data-active-badge>{{ $active }} Active {{ $active === 1 ? 'Student' : 'Students' }}</span></h2>
            <p class="ro-lead">Manage enrolled learners, preferred names and observations for {{ $currentClass->name }}.</p>
        </div>
        <div class="ro-meter glass no-print" data-capacity>
            <p class="ro-meter-text">Capacity load <strong>{{ $active }} / {{ $cap }} seats ({{ $percent }}%)</strong>@if ($active > $cap) <span class="ui-badge ui-badge-absent">Over limit</span>@elseif ($full) <span class="ui-badge ui-badge-absent">Full</span>@endif</p>
            @if ($active > $cap)<p class="ro-hint">This class holds more active students than its limit. Nothing was removed; adding and restoring are blocked until you archive students.</p>@endif
            <progress class="ui-progress ro-progress @if ($warn) is-warn @endif" value="{{ $active }}" max="{{ $cap }}" aria-label="Seats used: {{ $active }} of {{ $cap }}">{{ $percent }}%</progress>
        </div>
    </header>

    <div class="ro-actions no-print">
        <form method="POST" action="{{ url('/classes/'.$currentClass->id.'/students') }}" class="ro-quick glass" novalidate>
            @csrf
            <input type="hidden" name="form" value="quick">
            <x-icon name="user-plus" class="ro-quick-icon icon-20" />
            <div class="ro-quick-fields">
                <label for="quick-name" class="visually-hidden">Student first and last name</label>
                <input class="ui-input" id="quick-name" name="display_name" type="text" maxlength="120" required autocomplete="off" placeholder="Quick add: first &amp; last name…" value="{{ $quick ? old('display_name') : '' }}" @if ($err('display_name', 'quick')) aria-invalid="true" aria-describedby="quick-errors" @elseif ($err('roster_cap', 'quick')) aria-describedby="quick-errors" @endif>
                <label for="quick-number" class="visually-hidden">Student number (optional)</label>
                <input class="ui-input ro-quick-number" id="quick-number" name="student_number" type="text" maxlength="40" autocomplete="off" placeholder="Student no." value="{{ $quick ? old('student_number') : '' }}" @if ($err('student_number', 'quick')) aria-invalid="true" aria-describedby="quick-errors" @endif>
            </div>
            <button type="submit" class="ui-btn ui-btn-primary">Add</button>
        </form>
        <a class="ui-btn ro-import" href="{{ url('/classes/'.$currentClass->id.'/import') }}"><x-icon name="file-input" class="icon-18" />Bulk CSV Import</a>
        <button type="button" class="ui-btn ui-btn-icon" data-print-roster aria-label="Print roster" title="Print roster"><x-icon name="printer" class="icon-18" /></button>
    </div>
    @if ($quick && $errors->any())
        <div class="ro-quick-errors no-print" id="quick-errors" role="alert">
            @foreach (array_unique($errors->all()) as $message)<p class="ro-error">{{ $message }}</p>@endforeach
        </div>
    @endif

    <div class="ro-toolbar no-print">
        <div class="ui-search ro-search">
            <x-icon name="search" class="icon-18" />
            <label for="roster-search" class="visually-hidden">Search by student name, preferred name or number</label>
            <input id="roster-search" type="search" placeholder="Search name or ID…" autocomplete="off" aria-keyshortcuts="/" data-roster-search>
            <kbd class="ui-kbd" aria-hidden="true">/</kbd>
        </div>
        <div class="ro-sort">
            <label for="roster-sort" class="ui-eyebrow">Sort</label>
            <select id="roster-sort" class="ui-input" data-roster-sort>
                <option value="last-asc">Last name (A–Z)</option>
                <option value="name-asc">Name (A–Z)</option>
                <option value="avg-desc">Participation avg (high to low)</option>
                <option value="recent">Recently added</option>
            </select>
        </div>
        <p class="ro-count ui-badge ui-badge-info" role="status" aria-live="polite" data-roster-count data-total="{{ $active }}"><x-icon name="users" class="icon-16" /><span data-count-text>Showing <strong>{{ $active }}</strong> of {{ $active }} {{ $active === 1 ? 'student' : 'students' }}</span></p>
    </div>

    <div class="ui-table-wrap ro-table-wrap">
        <table class="ui-table ro-table" data-roster-table>
            <caption class="visually-hidden">Active students of {{ $currentClass->name }}</caption>
            <thead>
                <tr>
                    <th scope="col" class="ro-col-order">Order</th>
                    <th scope="col">Student info</th>
                    <th scope="col">Student ID</th>
                    <th scope="col">Observations</th>
                    <th scope="col">Semester avg</th>
                    <th scope="col" class="no-print">Today</th>
                    <th scope="col" class="no-print ro-col-actions">Actions</th>
                </tr>
            </thead>
            <tbody data-roster-body>
                @foreach ($rosterRows as $i => $row)
                    @php $s = $row['student']; @endphp
                    <tr data-student-row data-id="{{ $s->id }}" data-order="{{ $i }}" data-name="{{ $s->display_name }}" data-preferred="{{ $s->preferred_name }}" data-number="{{ $s->student_number }}" data-avg="{{ $row['average'] }}">
                        <td class="ro-col-order" data-label="Order"><span class="ro-order" data-order-cell>{{ $i + 1 }}</span></td>
                        <td class="ro-info" data-label="Student">
                            <div class="ro-info-inner">
                                <span class="ui-avatar ro-avatar" aria-hidden="true">{{ $row['initials'] }}</span>
                                <span class="ro-info-text">
                                    <span class="ro-name">{{ $s->display_name }}</span>
                                    @if (filled($s->preferred_name))<span class="ro-preferred">Preferred: {{ $s->preferred_name }}</span>@endif
                                </span>
                            </div>
                        </td>
                        <td class="ro-number" data-label="Student ID">{{ filled($s->student_number) ? $s->student_number : '—' }}</td>
                        <td class="ro-obs" data-label="Observations">@if (filled($s->observations))<span class="ro-obs-text" title="{{ $s->observations }}">{{ $s->observations }}</span>@else<span class="ro-empty" aria-label="No observations">—</span>@endif</td>
                        <td data-label="Semester avg"><span @class(['ro-avg', 'is-empty' => $row['average'] === null])>{{ $row['average_text'] }}</span></td>
                        <td class="no-print" data-label="Today">
                            @if ($row['today'] === 'present')
                                <span @class(['ui-badge', 'ui-badge-present' => $row['today_points'] > 0, 'ui-badge-zero' => $row['today_points'] === 0])>Present · {{ $row['today_points'] }} {{ $row['today_points'] === 1 ? 'pt' : 'pts' }}</span>
                            @elseif ($row['today'] === 'absent')
                                <span class="ui-badge ui-badge-absent">Absent</span>
                            @else
                                <span class="ui-badge ui-badge-none">Not recorded</span>
                            @endif
                        </td>
                        <td class="no-print ro-actions-cell" data-label="Actions">
                            <button type="button" class="ui-btn ui-btn-icon ro-icon-btn" data-edit-student
                                data-action="{{ url('/students/'.$s->id) }}" data-name="{{ $s->display_name }}" data-preferred="{{ $s->preferred_name }}" data-number="{{ $s->student_number }}" data-observations="{{ $s->observations }}"
                                aria-label="Edit {{ $s->display_name }}" title="Edit"><x-icon name="pencil" class="icon-18" /></button>
                            <details class="ro-archive">
                                <summary class="ui-btn ui-btn-icon ro-icon-btn" aria-label="Archive {{ $s->display_name }}" title="Archive"><x-icon name="archive" class="icon-18" /></summary>
                                <div class="ro-archive-body glass glass-strong">
                                    <form method="POST" action="{{ url('/students/'.$s->id.'/archive') }}">
                                        @csrf
                                        <p>Archive {{ $s->display_name }}? Their records are kept and you can restore them anytime.</p>
                                        <button type="submit" class="ui-btn ui-btn-danger ui-btn-sm" aria-label="Confirm archive {{ $s->display_name }}">Confirm archive</button>
                                    </form>
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
                <tr class="ro-empty-row" data-roster-empty @if ($active > 0) hidden @endif>
                    <td colspan="7" class="row-empty">
                        @if ($studentCount === 0)
                            No students yet. Use Quick add, or <a class="link-accent" href="{{ url('/classes/'.$currentClass->id.'/import') }}">import a CSV</a>.
                        @else
                            No active students.
                        @endif
                    </td>
                </tr>
                <tr class="ro-empty-row" data-roster-nomatch hidden><td colspan="7" class="row-empty">No students match your search.</td></tr>
            </tbody>
        </table>
    </div>
</section>
