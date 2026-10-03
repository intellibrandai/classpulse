{{-- Server-rendered per-day bars. weekly.js rebuilds the same structure from the week JSON (keep the two in step). --}}
<svg class="ui-chart ui-chart-blue wm-chart" viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" role="img" aria-label="{{ $chart['alt'] }}" focusable="false">
    <title>{{ $chart['alt'] }}</title>
    @foreach ($chart['bars'] as $bar)
        @if ($bar['is_gap'])
            <line class="ui-chart-gap" x1="{{ $bar['x'] }}" x2="{{ $bar['x'] + $bar['width'] }}" y1="{{ $chart['baseline_y'] - 1 }}" y2="{{ $chart['baseline_y'] - 1 }}"><title>{{ $bar['title'] }}</title></line>
        @else
            <rect @class(['ui-chart-bar', 'is-peak' => $bar['is_peak']]) x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['width'] }}" height="{{ $bar['height'] }}" rx="2"><title>{{ $bar['title'] }}</title></rect>
        @endif
    @endforeach
</svg>
