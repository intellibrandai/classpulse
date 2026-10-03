<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') @yield('title') - ClassPulse</title>
    <x-head-icons />
    <script src="{{ asset('js/theme-init.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/shell.css') }}">
    <link rel="stylesheet" href="{{ asset('css/screens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/glass.css') }}">
</head>
<body class="error-body">
    <x-icons />
    <main class="error-card glass">
        <div class="brand-cluster">
            <x-brand-mark />
            <span class="brand-text">
                <span class="brand-title">ClassPulse</span>
                <span class="brand-subtitle">Student Participation Tracker</span>
            </span>
        </div>
        <p class="error-code">@yield('code')</p>
        <h1 class="error-title">@yield('title')</h1>
        <p class="error-text">@yield('message')</p>
        <a class="action-btn action-btn-primary" href="{{ url('/daily') }}"><x-icon name="clipboard-list" class="icon-16" />Back to the Daily Tracker</a>
    </main>
</body>
</html>
