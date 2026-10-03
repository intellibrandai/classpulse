@php
    // Which form on the page a validation error belongs to (hidden "form" field, flashed back with the old input).
    $scope = old('form');
    $err = fn (string $key, string $form): ?string => $scope === $form ? $errors->first($key) : null;
    $known = ['create', 'details', 'quick', 'edit', 'restore', 'delete'];
    $stray = $errors->any() && ! in_array($scope, $known, true);
    $createOpen = $currentClass === null || request()->boolean('create') || $scope === 'create';
    $label = fn ($c): ?string => filled($c->title) ? $c->title : (filled($c->subject_description) ? $c->subject_description : null);
@endphp
@push('head')
    <link rel="stylesheet" href="{{ asset('css/roster.css') }}">
@endpush
<x-layouts.app
    title="Class Roster & Settings"
    active="roster"
    :classes="$classes"
    :current-class="$currentClass"
    print-title="Class Roster"
    :print-label="$currentClass ? $currentClass->name.' · '.count($rosterRows).' active '.(count($rosterRows) === 1 ? 'student' : 'students') : null"
>
    <x-slot:shortcuts>
        <span>Shortcuts: [/] Search students · [Esc] Close editor</span>
    </x-slot:shortcuts>

    <div class="roster-page" data-roster>
        <h1 class="visually-hidden">Class Roster &amp; Settings</h1>

        @if (session('status'))
            <div class="form-success notice no-print" role="status"><x-icon name="check-circle" class="icon-16" />{{ session('status') }}</div>
        @endif
        @if ($stray)
            <div class="form-error notice no-print" role="alert">
                @foreach ($errors->all() as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif

        @include('roster.partials.class-cards')

        @if ($currentClass === null)
            <section class="empty-state notice">
                <h2>Create your first class</h2>
                <p>Add a course above to start tracking participation.</p>
            </section>
            <div class="ro-account-solo no-print">
                @include('roster.partials.account')
            </div>
        @else
            <div class="ro-layout">
                <div class="ro-side no-print">
                    @include('roster.partials.details')
                    @include('roster.partials.rules')
                    @include('roster.partials.files')
                    @include('roster.partials.account')
                    @include('roster.partials.danger')
                </div>
                <div class="ro-main">
                    @include('roster.partials.roster')
                    @include('roster.partials.archived')
                </div>
            </div>
            @include('roster.partials.edit-dialog')
        @endif
    </div>

    <script src="{{ asset('js/roster-lib.js') }}" defer></script>
    <script src="{{ asset('js/roster.js') }}" defer></script>
    <script src="{{ asset('js/account.js') }}" defer></script>
</x-layouts.app>
