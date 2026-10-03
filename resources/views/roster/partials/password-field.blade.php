{{-- A password input with its show/hide toggle. Vars: id, name, label, autocomplete, error; optional hint. The value is never rendered. --}}
<div class="ro-field">
    <label class="ui-eyebrow" for="{{ $id }}">{{ $label }}</label>
    <div class="pw-wrap" data-pw-field>
        <input class="ui-input pw-input" id="{{ $id }}" name="{{ $name }}" type="password" value="" maxlength="255" autocomplete="{{ $autocomplete }}" autocapitalize="none" spellcheck="false" required
            @if (! empty($error)) aria-invalid="true" aria-describedby="{{ $id }}-error" @elseif (! empty($hint)) aria-describedby="{{ $id }}-hint" @endif>
        <button type="button" class="pw-toggle" data-pw-toggle aria-pressed="false" aria-controls="{{ $id }}" aria-label="Show password"><x-icon name="eye" class="pw-icon-show icon-18" /><x-icon name="eye-off" class="pw-icon-hide icon-18" /></button>
    </div>
    @if (! empty($error))<p class="ro-error" id="{{ $id }}-error" role="alert">{{ $error }}</p>@elseif (! empty($hint))<p class="ro-hint" id="{{ $id }}-hint">{{ $hint }}</p>@endif
</div>
