@php
    // Dedicated error bags: these two forms never collide with the class forms on this page.
    $emailBag = $errors->getBag('accountEmail');
    $passwordBag = $errors->getBag('accountPassword');
    $emailChanged = session('account_email_changed');
    $accountIssues = $emailBag->count() + $passwordBag->count();
    $accountOpen = $accountIssues > 0 || is_array($emailChanged);
    $minLength = config('classpulse.password_min_length');
@endphp
<section class="ro-card ro-account-card glass glass-violet" aria-labelledby="account-title">
    <details class="ro-group ro-group-solo" id="account" data-ro-group data-account-group{{ $accountOpen ? ' open' : '' }}>
        <summary class="ro-group-summary">
            <span class="ui-icon-chip ui-icon-chip-violet"><x-icon name="user" class="icon-18" /></span>
            <span class="ro-group-text"><span id="account-title" class="ro-group-title">Account</span><span class="ro-group-sub">Change your email or password</span></span>
            @if ($accountIssues > 0)<span class="ui-badge ui-badge-absent">{{ $accountIssues }} to fix</span>@endif
            <x-icon name="chevron-down" class="ro-group-caret icon-18" />
        </summary>
        <div class="ro-group-body ro-account">
            @if (is_array($emailChanged))
                <div class="form-success notice" role="status" tabindex="-1" data-account-flash data-old-email="{{ $emailChanged['old'] }}" data-new-email="{{ $emailChanged['new'] }}"><x-icon name="check-circle" class="icon-16" /><span>Your email was changed to {{ $emailChanged['new'] }}.</span></div>
            @endif

            <p class="ro-account-current"><span class="ui-eyebrow">Signed in as</span><span class="ro-account-email" data-account-current-email>{{ $accountEmail }}</span></p>

            <form method="POST" action="{{ route('account.email') }}" class="ro-account-form" data-account-form novalidate>
                @csrf
                <h3 class="ro-files-heading">Change email</h3>
                <div class="ro-field">
                    <label class="ui-eyebrow" for="account-new-email">New email</label>
                    <input class="ui-input" id="account-new-email" name="new_email" type="email" inputmode="email" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="255" required value="{{ $emailBag->any() ? old('new_email') : '' }}"
                        @if ($emailBag->has('new_email')) aria-invalid="true" aria-describedby="account-new-email-error" @endif>
                    @if ($emailBag->has('new_email'))<p class="ro-error" id="account-new-email-error" role="alert">{{ $emailBag->first('new_email') }}</p>@endif
                </div>
                @include('roster.partials.password-field', ['id' => 'account-email-password', 'name' => 'current_password', 'label' => 'Current password', 'autocomplete' => 'current-password', 'error' => $emailBag->first('current_password')])
                <div class="ro-form-actions">
                    <button type="submit" class="ui-btn ui-btn-primary"><x-icon name="check" class="icon-18" />Change email</button>
                </div>
            </form>

            <form method="POST" action="{{ route('account.password') }}" class="ro-account-form" data-account-form novalidate>
                @csrf
                <h3 class="ro-files-heading">Change password</h3>
                @include('roster.partials.password-field', ['id' => 'account-current-password', 'name' => 'current_password', 'label' => 'Current password', 'autocomplete' => 'current-password', 'error' => $passwordBag->first('current_password')])
                @include('roster.partials.password-field', ['id' => 'account-new-password', 'name' => 'password', 'label' => 'New password', 'autocomplete' => 'new-password', 'hint' => 'Use at least '.$minLength.' characters.', 'error' => $passwordBag->first('password')])
                @include('roster.partials.password-field', ['id' => 'account-confirm-password', 'name' => 'password_confirmation', 'label' => 'Confirm new password', 'autocomplete' => 'new-password', 'error' => $passwordBag->first('password_confirmation')])
                <p class="ro-hint">After the change, every device is signed out and you sign in again with the new password.</p>
                <div class="ro-form-actions">
                    <button type="submit" class="ui-btn ui-btn-primary"><x-icon name="lock" class="icon-18" />Change password</button>
                </div>
            </form>
        </div>
    </details>
</section>
