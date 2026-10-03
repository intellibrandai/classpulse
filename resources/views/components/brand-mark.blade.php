{{-- Official waveform logo: dark-theme and light-theme SVGs, swapped by CSS (public/css/shell.css .brand-mark-*). --}}
<span {{ $attributes->class(['brand-mark']) }} aria-hidden="true">
    <img class="brand-mark-dark" src="{{ asset('brand/classpulse-mark.svg') }}" alt="" width="96" height="42" decoding="async">
    <img class="brand-mark-light" src="{{ asset('brand/classpulse-mark-light.svg') }}" alt="" width="96" height="42" decoding="async">
</span>
