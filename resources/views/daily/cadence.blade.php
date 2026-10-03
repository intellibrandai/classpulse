@php
    // Last 8 weeks of the Full Semester; weeks without a present day are gaps (null), never zeros.
    $weeks = array_slice($weeks, -8);
    $withData = count(array_filter($weeks, fn ($w) => $w['average'] !== null));
@endphp
<div class="cadence">
    @if ($withData < 2)
        <p class="cadence-empty">Not enough weeks recorded yet</p>
    @else
        @php
            $values = array_map(fn ($w) => $w['average'], $weeks);
            $chart = \App\Support\BarSeries::build($values, 320, 64, 8);
            $parts = [];
            foreach ($weeks as $w) {
                $parts[] = 'week of '.\Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $w['monday'], 'UTC')->format('M j').': '.($w['average'] === null ? 'no data' : number_format($w['average'], 2, '.', ''));
            }
            $alt = 'Weekly average per present day, last '.count($weeks).' weeks: '.implode('; ', $parts);
        @endphp
        <svg class="ui-chart cadence-chart" viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" role="img" aria-label="{{ $alt }}" focusable="false">
            <title>{{ $alt }}</title>
            @foreach ($chart['bars'] as $bar)
                @if (! $bar['is_gap'])
                    <rect @class(['ui-chart-bar', 'is-last' => $bar['is_last']]) x="{{ round($bar['x'], 2) }}" y="{{ round($bar['y'], 2) }}" width="{{ round($bar['width'], 2) }}" height="{{ max(round($bar['height'], 2), 2) }}" rx="2"><title>{{ $parts[$bar['index']] }}</title></rect>
                @else
                    <line class="cadence-gap" x1="{{ round($bar['x'], 2) }}" x2="{{ round($bar['x'] + $bar['width'], 2) }}" y1="{{ round($chart['baseline_y'] - 1, 2) }}" y2="{{ round($chart['baseline_y'] - 1, 2) }}" stroke-dasharray="2 2"/>
                @endif
            @endforeach
        </svg>
        <p class="cadence-caption">Average per present day, by week · last week highlighted</p>
        <p class="visually-hidden">{{ $alt }}</p>
    @endif
</div>
