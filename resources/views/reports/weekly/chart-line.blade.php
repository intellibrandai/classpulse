{{-- Server-rendered sparkline. weekly.js rebuilds the same structure from the week JSON (keep the two in step). --}}
<svg class="ui-chart ui-chart-violet wm-chart" viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" role="img" aria-label="{{ $chart['alt'] }}" focusable="false">
    <title>{{ $chart['alt'] }}</title>
    @if ($chart['path'] !== '')
        <path d="{{ $chart['path'] }}"/>
    @endif
    @foreach ($chart['dots'] as $dot)
        <circle class="ui-chart-dot" cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}" r="{{ $dot['is_last'] ? 3 : 2 }}"><title>{{ $dot['title'] }}</title></circle>
    @endforeach
</svg>
