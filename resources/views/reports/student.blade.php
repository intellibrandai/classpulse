<x-layouts.app :title="$student->display_name" active="semester" :classes="$classes" :current-class="$currentClass">
    <div class="page-card-header">
        <div>
            <h1 class="page-card-title">{{ $student->display_name }}@if ($student->isArchived()) (archived)@endif</h1>
            <p class="page-card-desc">
                Student detail · Class: {{ $currentClass->name }}
                @if ($period['from'] !== null)
                    · Period: {{ $period['from'] }} to {{ $period['to'] }}
                @endif
            </p>
        </div>
        <div class="page-card-actions">
            <a class="action-btn" href="{{ url('/semester?class='.$currentClass->id) }}"><x-icon name="arrow-left" class="icon-16" />Back to Semester Analytics</a>
            <a class="action-btn action-btn-primary" href="{{ $exportUrl }}"><x-icon name="download" class="icon-16" />Export CSV</a>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="kpi-label">Total points</div><span class="kpi-val-giant">{{ $summary['total_points'] }}</span></div>
        <div class="stat-card"><div class="kpi-label">Present days recorded</div><span class="kpi-val-giant">{{ $summary['present_days_recorded'] }}</span></div>
        <div class="stat-card"><div class="kpi-label">Absences</div><span class="kpi-val-giant">{{ $summary['absences'] }}</span></div>
        <div class="stat-card"><div class="kpi-label">Average per present day</div><span @class(['kpi-val-giant', 'emerald' => count($history) > 0, 'is-empty' => count($history) === 0])>{{ $average }}</span></div>
    </div>

    @if (count($history) === 0)
        <p class="empty-state">No data</p>
    @else
        <div class="split-grid">
            <div class="table-container" role="region" aria-label="Recorded dates" tabindex="0">
                <div class="table-head"><h2>Recorded dates</h2></div>
                <table class="matrix-table">
                    <thead>
                        <tr><th scope="col">Date</th><th scope="col">Weekday</th><th scope="col">Status</th><th scope="col" class="num">Points</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $row)
                            <tr>
                                <td>{{ $row['date'] }}</td>
                                <td>{{ $row['weekday'] }}</td>
                                <td>{{ $row['status'] }}</td>
                                <td class="num strong">{{ $row['points'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="table-container" role="region" aria-label="Weekly subtotals" tabindex="0">
                <div class="table-head"><h2>Weekly subtotals</h2></div>
                <table class="matrix-table">
                    <thead>
                        <tr><th scope="col">Week of</th><th scope="col" class="num">Total points</th><th scope="col" class="num">Present days recorded</th><th scope="col" class="num">Absences</th><th scope="col" class="num">Average per present day</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($weeks as $week)
                            <tr>
                                <td>{{ $week['monday'] }}</td>
                                <td class="num strong">{{ $week['total_points'] }}</td>
                                <td class="num">{{ $week['present_days_recorded'] }}</td>
                                <td class="num">{{ $week['absences'] }}</td>
                                <td class="num">{{ $week['average'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-layouts.app>
