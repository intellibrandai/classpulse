@props(['name'])
{{-- Inline SVG icon from the sprite in components/icons.blade.php. Decorative by default (aria-hidden): put the accessible name on the parent control. --}}
<svg {{ $attributes->class(['icon']) }} viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="#icon-{{ $name }}"/></svg>
