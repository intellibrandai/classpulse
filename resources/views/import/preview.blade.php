@push('head')
    <link rel="stylesheet" href="{{ asset('css/roster.css') }}">
@endpush
<x-layouts.app title="Import Preview" active="roster" :classes="$classes" :current-class="$currentClass">
    @php($counts = collect($rows)->countBy('status'))
    <div class="import-page">
        <header class="imp-head glass glass-violet">
            <div class="imp-head-text">
                <a class="imp-back" href="{{ url('/roster?class='.$currentClass->id) }}"><x-icon name="arrow-left" class="icon-16" />Back to roster</a>
                <h1 class="imp-title"><span class="ui-icon-chip ui-icon-chip-violet"><x-icon name="eye" class="icon-18" /></span>Import preview for {{ $currentClass->name }}</h1>
                <p class="ro-lead">Step 2 of 2 · tick the rows to import; rows with an error cannot be imported.</p>
            </div>
            <span class="ui-badge ui-badge-violet">Step 2 of 2</span>
        </header>

        @if ($errors->any())
            <div class="form-error notice" role="alert">
                @foreach ($errors->all() as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif

        <p class="ro-lead imp-seats" data-import-seats>Seats in {{ $currentClass->name }}: <strong>{{ $capacity['active'] }} of {{ $capacity['limit'] }}</strong> active students, <strong>{{ $capacity['remaining'] }}</strong> free.</p>

        @if ($capacity['message'] !== null)
            <div class="form-error notice" role="alert" data-import-over-capacity>
                <p>{{ $capacity['message'] }}</p>
            </div>
        @endif

        @if (! $hasValid)
            <p class="empty-state notice">No valid rows to import.</p>
        @endif

        <ul class="import-counts" aria-label="Preview summary">
            <li class="pill pill-ok">{{ $counts->get('OK', 0) }} OK</li>
            <li class="pill pill-warn">{{ $counts->get('WARNING', 0) }} WARNING</li>
            <li class="pill pill-error">{{ $counts->get('ERROR', 0) }} ERROR</li>
        </ul>

        <form method="POST" action="{{ url('/classes/'.$currentClass->id.'/import/commit') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <p class="scroll-hint">Scroll sideways inside the table to see every column</p>
            <div class="ui-table-wrap imp-table-wrap" role="region" aria-label="Import preview rows" tabindex="0">
                <table class="ui-table imp-table">
                    <thead>
                        <tr>
                            <th scope="col">Import</th>
                            <th scope="col" class="num">Row</th>
                            <th scope="col">Name</th>
                            <th scope="col">Student number</th>
                            <th scope="col">Status</th>
                            <th scope="col">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>
                                    <label class="check-cell">
                                        <input type="checkbox" name="rows[]" value="{{ $row['row'] }}" id="row-{{ $row['row'] }}" aria-label="Import row {{ $row['row'] }}"
                                            @checked($row['status'] === 'OK') @disabled($row['status'] === 'ERROR')>
                                    </label>
                                </td>
                                <td class="num">{{ $row['row'] }}</td>
                                <td class="ro-name">{{ $row['name'] }}</td>
                                <td class="ro-number">{{ $row['student_number'] }}</td>
                                <td>
                                    <span @class(['pill', 'pill-ok' => $row['status'] === 'OK', 'pill-warn' => $row['status'] === 'WARNING', 'pill-error' => $row['status'] === 'ERROR'])>{{ $row['status'] }}</span>
                                </td>
                                <td>{{ $row['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ro-form-actions imp-actions">
                @if ($hasValid)
                    <button type="submit" class="ui-btn ui-btn-primary"><x-icon name="upload" class="icon-18" />Import selected students</button>
                @endif
                <a class="ui-btn" href="{{ url('/classes/'.$currentClass->id.'/import') }}"><x-icon name="refresh-cw" class="icon-18" />Start over</a>
                <a class="ui-btn ui-btn-ghost" href="{{ url('/roster?class='.$currentClass->id) }}">Back to roster</a>
            </div>
        </form>
    </div>
</x-layouts.app>
