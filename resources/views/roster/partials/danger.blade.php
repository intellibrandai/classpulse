<section class="ro-card ro-danger glass glass-red" aria-labelledby="danger-title">
    <details class="ro-danger-details" @if ($scope === 'delete') open @endif>
        <summary id="danger-title"><x-icon name="alert-triangle" class="icon-18" /><span>Delete this class</span></summary>
        <p class="ro-danger-note">This permanently deletes {{ $currentClass->name }}, {{ $studentCount }} {{ $studentCount === 1 ? 'student' : 'students' }} and {{ $entryCount }} {{ $entryCount === 1 ? 'entry' : 'entries' }}. It cannot be undone. This is the only action on this page that removes records for good; archiving a student never does.</p>
        <form method="POST" action="{{ url('/classes/'.$currentClass->id) }}" class="ro-form" novalidate>
            @csrf
            @method('DELETE')
            <input type="hidden" name="form" value="delete">
            <div class="ro-field">
                <label class="ui-eyebrow" for="confirm-name">Type the class name to confirm</label>
                <input class="ui-input" id="confirm-name" name="confirm_name" type="text" autocomplete="off" required @if ($err('confirm_name', 'delete')) aria-invalid="true" aria-describedby="confirm-name-error" @endif>
                @if ($err('confirm_name', 'delete'))<p class="ro-error" id="confirm-name-error" role="alert">{{ $err('confirm_name', 'delete') }}</p>@endif
            </div>
            <div class="ro-form-actions"><button type="submit" class="ui-btn ui-btn-danger"><x-icon name="alert-triangle" class="icon-18" />Delete class</button></div>
        </form>
    </details>
</section>
