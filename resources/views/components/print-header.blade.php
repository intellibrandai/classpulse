@props(['title' => null, 'label' => null, 'schoolClass' => null])
{{-- Print-only header (hidden on screen by print.css). Pages opt in through the layout props print-title / print-label. --}}
@php
    $printedAt = \Carbon\CarbonImmutable::now(config('classpulse.school_timezone', 'America/Toronto'));
@endphp
<div {{ $attributes->class(['print-header']) }}>
    <img class="print-logo" src="{{ asset('brand/classpulse-mark-light.svg') }}" alt="ClassPulse" width="96" height="42">
    <div class="print-heading">
        <p class="print-brand">ClassPulse · Student Participation Tracker</p>
        <p class="print-title">
            @if ($schoolClass)<span class="print-class">{{ $schoolClass->name }}@if (filled($schoolClass->title)) · {{ $schoolClass->title }}@endif</span>@endif
            @if ($title)<span class="print-doc">{{ $title }}</span>@endif
        </p>
        @if ($label)<p class="print-label">{{ $label }}</p>@endif
    </div>
    <p class="print-stamp">Printed on <time data-printed-at data-tz="{{ config('classpulse.school_timezone', 'America/Toronto') }}" datetime="{{ $printedAt->toIso8601String() }}">{{ $printedAt->format('M j, Y g:i A') }}</time></p>
</div>
