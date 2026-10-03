<nav class="ro-classes no-print" aria-label="Classes">
    @foreach ($classCards as $card)
        @php $sub = $label($card); @endphp
        <a class="ro-class-card glass @if ($currentClass && $currentClass->id === $card->id) glass-selected is-current @endif" href="{{ url('/roster?class='.$card->id) }}" @if ($currentClass && $currentClass->id === $card->id) aria-current="true" @endif>
            <span class="ui-icon-chip @if ($currentClass && $currentClass->id === $card->id) ui-icon-chip-violet @else ui-icon-chip-blue @endif"><x-icon name="users" class="icon-18" /></span>
            <span class="ro-class-text">
                <span class="ro-class-code">{{ $card->name }}@if ($currentClass && $currentClass->id === $card->id)<span class="ro-class-dot" aria-hidden="true"></span><span class="visually-hidden">Active class</span>@endif</span>
                <span class="ro-class-sub">@if ($sub){{ $sub }} • @endif{{ $card->active_students_count }} {{ $card->active_students_count === 1 ? 'student' : 'students' }}</span>
            </span>
        </a>
    @endforeach
    <a class="ro-class-card ro-class-new glass glass-green" href="#create-class" data-open-create>
        <span class="ui-icon-chip ui-icon-chip-green"><x-icon name="plus" class="icon-18" /></span>
        <span class="ro-class-text"><span class="ro-class-code">Create New Class</span></span>
    </a>
</nav>

<details class="ro-create glass glass-green no-print" id="create-class" @if ($createOpen) open @endif>
    <summary><x-icon name="plus" class="icon-18" /><span>Create New Class</span></summary>
    <form method="POST" action="{{ url('/classes') }}" class="ro-form" novalidate>
        @csrf
        <input type="hidden" name="form" value="create">
        <div class="ro-grid ro-grid-3">
            @include('roster.partials.field', ['id' => 'new-name', 'name' => 'name', 'label' => 'Course code', 'value' => old('form') === 'create' ? old('name') : '', 'max' => 60, 'required' => true, 'error' => $err('name', 'create')])
            @include('roster.partials.field', ['id' => 'new-title', 'name' => 'title', 'label' => 'Course title', 'value' => old('form') === 'create' ? old('title') : '', 'max' => 120, 'error' => $err('title', 'create')])
            @include('roster.partials.field', ['id' => 'new-subject', 'name' => 'subject_description', 'label' => 'Subject description', 'value' => old('form') === 'create' ? old('subject_description') : '', 'max' => 120, 'error' => $err('subject_description', 'create')])
            @include('roster.partials.field', ['id' => 'new-period', 'name' => 'period_label', 'label' => 'Period / Block', 'value' => old('form') === 'create' ? old('period_label') : '', 'max' => 40, 'placeholder' => 'Period 2', 'error' => $err('period_label', 'create')])
            @include('roster.partials.field', ['id' => 'new-schedule', 'name' => 'schedule', 'label' => 'Schedule', 'value' => old('form') === 'create' ? old('schedule') : '', 'max' => 80, 'placeholder' => '10:15 - 11:35', 'error' => $err('schedule', 'create')])
            @include('roster.partials.field', ['id' => 'new-room', 'name' => 'room', 'label' => 'Room / Lab', 'value' => old('form') === 'create' ? old('room') : '', 'max' => 60, 'error' => $err('room', 'create')])
            @include('roster.partials.field', ['id' => 'new-cap', 'name' => 'roster_cap', 'label' => 'Roster cap', 'type' => 'number', 'value' => old('form') === 'create' ? old('roster_cap') : config('classpulse.max_roster'), 'min' => 1, 'maxnum' => config('classpulse.max_roster'), 'required' => true, 'hint' => 'Up to '.config('classpulse.max_roster').' seats.', 'error' => $err('roster_cap', 'create')])
            @include('roster.partials.field', ['id' => 'new-start', 'name' => 'semester_start', 'label' => 'Semester start', 'type' => 'date', 'value' => old('form') === 'create' ? old('semester_start') : '', 'error' => $err('semester_start', 'create')])
            @include('roster.partials.field', ['id' => 'new-end', 'name' => 'semester_end', 'label' => 'Semester end', 'type' => 'date', 'value' => old('form') === 'create' ? old('semester_end') : '', 'error' => $err('semester_end', 'create')])
        </div>
        <div class="ro-form-actions">
            <button type="submit" class="ui-btn ui-btn-primary"><x-icon name="plus" class="icon-18" />Create class</button>
        </div>
    </form>
</details>
