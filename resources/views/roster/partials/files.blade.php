<section class="ro-card ro-files-card glass glass-blue" aria-labelledby="files-title">
    <details class="ro-group ro-group-solo" id="files" data-ro-group>
        <summary class="ro-group-summary">
            <span class="ui-icon-chip ui-icon-chip-blue"><x-icon name="download" class="icon-18" /></span>
            <span class="ro-group-text"><span id="files-title" class="ro-group-title">Files &amp; backups</span><span class="ro-group-sub">Import a roster, export reports</span></span>
            <x-icon name="chevron-down" class="ro-group-caret icon-18" />
        </summary>
        <div class="ro-group-body ro-files">
            <p class="ro-files-lead">Import a CSV downloaded from Google Sheets, Drive, or Dropbox. Save exported reports wherever you prefer.</p>

            <div class="ro-files-block">
                <h3 class="ro-files-heading">Import roster</h3>
                <a class="ui-btn ro-files-import" href="{{ $exports['import'] }}"><x-icon name="file-input" class="icon-18" />Import roster into {{ $currentClass->name }}</a>
            </div>

            <div class="ro-files-block">
                <h3 class="ro-files-heading">Export reports</h3>
                <ul class="ro-files-list" aria-label="CSV exports for {{ $currentClass->name }}">
                    <li class="ro-files-item">
                        <span class="ro-files-name">{{ $exports['weekly']['label'] }}</span>
                        <a class="ui-btn ui-btn-sm" href="{{ $exports['weekly']['url'] }}" aria-label="Download {{ $exports['weekly']['label'] }}"><x-icon name="download" class="icon-16" />Download</a>
                    </li>
                    @foreach ($exports['semester'] as $export)
                        <li class="ro-files-item">
                            <span class="ro-files-name">{{ $export['label'] }}</span>
                            <a class="ui-btn ui-btn-sm" href="{{ $export['url'] }}" aria-label="Download {{ $export['label'] }}"><x-icon name="download" class="icon-16" />Download</a>
                        </li>
                    @endforeach
                </ul>
                <p class="ro-hint">Each student's history CSV is on that student's History page (the History link on a student card in the Daily Tracker).</p>
            </div>

            <p class="ro-files-note"><x-icon name="info" class="icon-16" /><span>CSV reports are for reading and sharing. ClassPulse does not create a complete, restorable backup yet; back up the whole database from your hosting panel (see the owner guide).</span></p>
        </div>
    </details>
</section>
