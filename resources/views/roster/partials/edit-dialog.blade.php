@php
    $editing = $scope === 'edit';
    $editId = $editing ? (int) old('edit_student') : 0;
@endphp
<dialog id="edit-dialog" class="ui-dialog ro-dialog no-print" aria-labelledby="edit-dialog-title" @if ($editing) data-open-on-load @endif>
    <form method="POST" action="{{ $editing ? url('/students/'.$editId) : '' }}" class="ro-form" data-edit-form novalidate>
        @csrf
        @method('PUT')
        <input type="hidden" name="form" value="edit">
        <input type="hidden" name="edit_student" value="{{ $editing ? $editId : '' }}" data-edit-id>
        <header class="ro-dialog-head">
            <h2 id="edit-dialog-title" class="ro-card-title"><span class="ui-icon-chip ui-icon-chip-violet"><x-icon name="pencil" class="icon-18" /></span>Edit student</h2>
            <button type="button" class="ui-btn ui-btn-icon ui-btn-ghost" data-edit-close aria-label="Close"><x-icon name="x" class="icon-18" /></button>
        </header>
        @include('roster.partials.field', ['id' => 'edit-display', 'name' => 'display_name', 'label' => 'Display name', 'value' => $editing ? old('display_name') : '', 'max' => 120, 'required' => true, 'error' => $err('display_name', 'edit')])
        @include('roster.partials.field', ['id' => 'edit-preferred', 'name' => 'preferred_name', 'label' => 'Preferred name', 'value' => $editing ? old('preferred_name') : '', 'max' => 80, 'error' => $err('preferred_name', 'edit')])
        @include('roster.partials.field', ['id' => 'edit-number', 'name' => 'student_number', 'label' => 'Student number', 'value' => $editing ? old('student_number') : '', 'max' => 40, 'error' => $err('student_number', 'edit')])
        <div class="ro-field">
            <label class="ui-eyebrow" for="edit-observations">Observations</label>
            <textarea class="ui-input" id="edit-observations" name="observations" maxlength="2000" rows="5" @if ($err('observations', 'edit')) aria-invalid="true" aria-describedby="edit-observations-error" @endif>{{ $editing ? old('observations') : '' }}</textarea>
            @if ($err('observations', 'edit'))<p class="ro-error" id="edit-observations-error" role="alert">{{ $err('observations', 'edit') }}</p>@endif
            <p class="ro-hint">Roster-level notes about this student (up to 2000 characters). Dated notes stay in Daily Tracker and Semester Analytics.</p>
        </div>
        <div class="ro-form-actions">
            <button type="submit" class="ui-btn ui-btn-primary"><x-icon name="check" class="icon-18" />Save student</button>
            <button type="button" class="ui-btn" data-edit-close>Cancel</button>
        </div>
    </form>
</dialog>
