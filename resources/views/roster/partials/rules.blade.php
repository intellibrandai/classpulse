<section class="ro-card ro-rules-card glass glass-green" aria-labelledby="rules-title">
    <details class="ro-group ro-group-solo" data-ro-group>
        <summary class="ro-group-summary">
            <span class="ui-icon-chip ui-icon-chip-green"><x-icon name="shield-check" class="icon-18" /></span>
            <span class="ro-group-text"><span id="rules-title" class="ro-group-title">Calculation Rules</span><span class="ro-group-sub">Locked: the same for every class</span></span>
            <span class="ui-badge ui-badge-present">Always on</span>
            <x-icon name="chevron-down" class="ro-group-caret icon-18" />
        </summary>
        <div class="ro-group-body">
        <ul class="ro-rules">
            <li class="ro-rule"><x-icon name="lock" class="icon-16" /><div><p class="ro-rule-title">Floor limit (zero-bound)</p><p class="ro-rule-text">Participation points never drop below 0.</p></div></li>
            <li class="ro-rule"><x-icon name="lock" class="icon-16" /><div><p class="ro-rule-title">Exclude absent days</p><p class="ro-rule-text">Absences are left out of every average.</p></div></li>
            <li class="ro-rule"><x-icon name="lock" class="icon-16" /><div><p class="ro-rule-title">Exclude not-recorded days</p><p class="ro-rule-text">Days without an entry are left out of every average.</p></div></li>
            <li class="ro-rule"><x-icon name="lock" class="icon-16" /><div><p class="ro-rule-title">Points are not converted to a grade</p><p class="ro-rule-text">Participation weight is not applied without a defined conversion scale.</p></div></li>
        </ul>
        </div>
    </details>
</section>
