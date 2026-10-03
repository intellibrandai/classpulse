@push('head')
    <link rel="stylesheet" href="{{ asset('css/roster.css') }}">
@endpush
<x-layouts.app title="Import Students" active="roster" :classes="$classes" :current-class="$currentClass">
    <div class="import-page">
        <header class="imp-head glass glass-violet">
            <div class="imp-head-text">
                <a class="imp-back" href="{{ url('/roster?class='.$currentClass->id) }}"><x-icon name="arrow-left" class="icon-16" />Back to roster</a>
                <h1 class="imp-title"><span class="ui-icon-chip ui-icon-chip-violet"><x-icon name="file-input" class="icon-18" /></span>Import students into {{ $currentClass->name }}</h1>
                <p class="ro-lead">Step 1 of 2 · you review every row before anything is saved.</p>
            </div>
            <span class="ui-badge ui-badge-violet">Step 1 of 2</span>
        </header>

        @if ($errors->any())
            <div class="form-error notice" role="alert">
                @foreach ($errors->all() as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif

        <section class="ro-card glass imp-panel">
            <p class="ro-lead imp-lead">Upload a CSV or TXT file (up to 256 KB) with a <code class="code-inline">name</code> column, or <code class="code-inline">first_name</code> and <code class="code-inline">last_name</code> columns and an optional <code class="code-inline">student_number</code>. Or paste names below. Choose one of the two.</p>
            <form method="POST" action="{{ url('/classes/'.$currentClass->id.'/import/preview') }}" enctype="multipart/form-data" class="ro-form">
                @csrf
                <div class="ro-field">
                    <label class="ui-eyebrow" for="import-file">CSV or TXT file</label>
                    <input class="ui-input imp-file" id="import-file" name="file" type="file" accept=".csv,.txt">
                </div>
                <div class="separator" aria-hidden="true"><span>or</span></div>
                <div class="ro-field">
                    <label class="ui-eyebrow" for="import-pasted">Paste names, one per line</label>
                    <textarea class="ui-input" id="import-pasted" name="pasted" rows="8" maxlength="262144">{{ old('pasted') }}</textarea>
                </div>
                <div class="ro-form-actions">
                    <button type="submit" class="ui-btn ui-btn-primary"><x-icon name="eye" class="icon-18" />Preview import</button>
                    <a class="ui-btn" href="{{ url('/roster?class='.$currentClass->id) }}">Cancel</a>
                </div>
            </form>
        </section>
    </div>
</x-layouts.app>
