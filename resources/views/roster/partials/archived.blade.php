<details class="ro-card ro-archived glass no-print" id="archived" @if ($scope === 'restore') open @endif>
    <summary>
        <x-icon name="archive" class="icon-18" /><span class="ro-archived-title">Archived students</span>
        <span class="ui-badge">{{ $archivedStudents->count() }}</span>
    </summary>
    @if ($scope === 'restore' && $errors->has('roster_cap'))
        <p class="ro-error ro-archived-error" role="alert">{{ $errors->first('roster_cap') }}</p>
    @endif
    @if ($archivedStudents->isEmpty())
        <p class="ro-lead">No archived students. Archived students keep all their records and can be restored anytime.</p>
    @else
        <ul class="ro-archived-list">
            @foreach ($archivedStudents as $s)
                <li class="ro-archived-row">
                    <span class="ui-avatar ro-avatar" aria-hidden="true">{{ \App\Support\StudentName::initials($s->display_name) }}</span>
                    <span class="ro-info-text">
                        <span class="ro-name">{{ $s->display_name }}</span>
                        <span class="ro-preferred">Archived {{ $s->archivedOn() }} · records kept{{ filled($s->student_number) ? " · ID ".$s->student_number : "" }}</span>
                    </span>
                    <form method="POST" action="{{ url('/students/'.$s->id.'/restore') }}">
                        @csrf
                        <input type="hidden" name="form" value="restore">
                        <button type="submit" class="ui-btn ui-btn-sm" aria-label="Restore {{ $s->display_name }}"><x-icon name="archive-restore" class="icon-16" />Restore</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</details>
