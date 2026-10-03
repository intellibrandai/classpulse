<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Track student participation and attendance, organize your classes, and review progress across the week and semester.">
    <title>Sign in - ClassPulse</title>
    <x-head-icons />
    <script src="{{ asset('js/theme-init.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/shell.css') }}">
    <link rel="stylesheet" href="{{ asset('css/screens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/glass.css') }}">
    <script src="{{ asset('js/theme.js') }}" defer></script>
    <script src="{{ asset('js/login-remember.js') }}" defer></script>
</head>
<body class="login-body">
    <x-icons />
    <div class="login-container">
        <div class="login-hero">
            <div class="brand-cluster">
                <x-brand-mark />
                <span class="brand-text">
                    <span class="brand-title">ClassPulse</span>
                    <span class="brand-subtitle">Student Participation Tracker</span>
                </span>
            </div>

            <div class="login-hero-body">
                <p class="login-headline">Class participation, one tap at a time.</p>
                <p class="login-tagline">Track student participation and attendance, organize your classes, and review progress across the week and semester.</p>
                <x-brand-mark class="brand-mark-xl" />
            </div>

            <div class="login-hero-foot">ClassPulse · Student Participation Tracker</div>
        </div>

        <main class="login-form-side">
            <x-theme-switch class="login-theme-toggle" />

            <div class="login-card glass">
                <div class="brand-cluster login-card-brand">
                    <x-brand-mark />
                    <span class="brand-text">
                        <span class="brand-title">ClassPulse</span>
                    </span>
                </div>

                <div class="login-card-header">
                    <h1 class="login-card-title">Sign in</h1>
                    <p class="login-card-subtitle">Student Participation Tracker</p>
                </div>

                @if (session('status'))
                    <p class="form-success login-status" role="status"><x-icon name="check-circle" class="icon-16" /><span>{{ session('status') }}</span></p>
                @endif

                @if (isset($throttleSeconds))
                    <p class="form-error" role="alert">Too many sign-in attempts. Try again in {{ $throttleSeconds }} seconds.</p>
                @elseif ($errors->any())
                    <p class="form-error" role="alert">{{ $errors->first() }}</p>
                @endif

                <form method="POST" action="{{ url('/login') }}">
                    @csrf
                    <div class="form-group">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-input" id="password" name="password" type="password" autocomplete="current-password" required>
                    </div>
                    <div class="form-group form-group-check">
                        <label class="check-row" for="remember-email">
                            <input class="check-row-input" id="remember-email" type="checkbox" data-remember-email aria-describedby="remember-email-hint">
                            <span class="check-row-text">Remember my email</span>
                        </label>
                        <p class="form-hint" id="remember-email-hint">Saved only in this browser. Your password is never saved.</p>
                    </div>
                    <button type="submit" class="action-btn action-btn-primary action-btn-block">Sign in</button>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
