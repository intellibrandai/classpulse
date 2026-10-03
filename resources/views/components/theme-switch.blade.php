{{-- Two-state sun/moon segmented control. The active half follows <html data-theme> through CSS (no flash); public/js/theme.js persists the choice. --}}
<div {{ $attributes->class(['theme-switch']) }} role="group" aria-label="Color theme">
    <button type="button" class="theme-switch-btn" data-theme-set="light" aria-pressed="false" aria-label="Light theme" title="Light theme"><x-icon name="sun" class="icon-16" /></button>
    <button type="button" class="theme-switch-btn" data-theme-set="dark" aria-pressed="false" aria-label="Dark theme" title="Dark theme"><x-icon name="moon" class="icon-16" /></button>
</div>
