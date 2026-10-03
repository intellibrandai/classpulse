{{-- One labelled input with its inline error. Vars: id, name, label, value, error; optional type, max, min, maxnum, required, placeholder, hint, span --}}
@php $type = $type ?? 'text'; @endphp
<div class="ro-field @if (! empty($span)) {{ $span }} @endif">
    <label class="ui-eyebrow" for="{{ $id }}">{{ $label }}@if (! empty($required)) <span class="ro-req" aria-hidden="true">*</span>@endif</label>
    <input class="ui-input" id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
        @if (isset($max)) maxlength="{{ $max }}" @endif
        @if (isset($min)) min="{{ $min }}" @endif
        @if (isset($maxnum)) max="{{ $maxnum }}" @endif
        @if (! empty($placeholder)) placeholder="{{ $placeholder }}" @endif
        @if (! empty($required)) required @endif
        @if (! empty($error)) aria-invalid="true" aria-describedby="{{ $id }}-error" @elseif (! empty($hint)) aria-describedby="{{ $id }}-hint" @endif
        value="{{ $value }}">
    @if (! empty($error))<p class="ro-error" id="{{ $id }}-error" role="alert">{{ $error }}</p>@elseif (! empty($hint))<p class="ro-hint" id="{{ $id }}-hint">{{ $hint }}</p>@endif
</div>
