@props(['id', 'label'])
{{-- Small "i" button with a plain-English definition. Shown on hover and keyboard focus (CSS), Esc dismisses (weekly.js). --}}
<span class="info-tip">
    <button type="button" class="info-tip-btn" aria-label="About {{ $label }}" aria-describedby="{{ $id }}"><x-icon name="info" class="icon-16" /></button>
    <span class="info-tip-text" role="tooltip" id="{{ $id }}">{{ $slot }}</span>
</span>
