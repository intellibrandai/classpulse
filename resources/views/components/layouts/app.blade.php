@props(['title' => 'ClassPulse', 'active' => 'daily', 'classes' => collect(), 'currentClass' => null, 'mainClass' => '', 'printTitle' => null, 'printLabel' => null])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} - ClassPulse</title>
    <x-head-icons />
    <script src="{{ asset('js/theme-init.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/shell.css') }}">
    <link rel="stylesheet" href="{{ asset('css/screens.css') }}">
    @stack('head')
    <link rel="stylesheet" href="{{ asset('css/glass.css') }}">
    <link rel="stylesheet" href="{{ asset('css/print.css') }}" media="print">
    <script src="{{ asset('js/theme.js') }}" defer></script>
    <script src="{{ asset('js/shell.js') }}" defer></script>
    <script src="{{ asset('js/focus-scroll.js') }}" defer></script>
</head>
<body class="app-body">
    <x-icons />
    <a class="skip-link" href="#main">Skip to main content</a>
    <header class="top-nav">
        <div class="nav-left">
            <a class="brand-cluster" href="{{ url('/daily') }}" aria-label="ClassPulse, Student Participation Tracker">
                <x-brand-mark />
                <span class="brand-text">
                    <span class="brand-title">ClassPulse</span>
                    <span class="brand-subtitle">Student Participation Tracker</span>
                </span>
            </a>

            <div class="class-switch">
                @if ($classes->isNotEmpty())
                    <x-class-menu :classes="$classes" :current="$currentClass" variant="header" />
                @endif
                <a class="btn-icon class-add" href="{{ url('/roster?create=1') }}" aria-label="Create a new class" title="Create a new class"><x-icon name="plus" class="icon-18" /></a>
            </div>
        </div>

        <nav class="screen-tabs" aria-label="Main">
            <a class="screen-tab-btn" href="{{ url('/daily') }}" aria-label="Daily Tracker" title="Daily Tracker" @if ($active === 'daily') aria-current="page" @endif><x-icon name="clipboard-list" class="tab-icon icon-18" /><span class="tab-full">Daily Tracker</span><span class="tab-short" aria-hidden="true">Daily</span></a>
            <a class="screen-tab-btn" href="{{ url('/weekly') }}" aria-label="Weekly Matrix" title="Weekly Matrix" @if ($active === 'weekly') aria-current="page" @endif><x-icon name="grid" class="tab-icon icon-18" /><span class="tab-full">Weekly Matrix</span><span class="tab-short" aria-hidden="true">Weekly</span></a>
            <a class="screen-tab-btn" href="{{ url('/semester') }}" aria-label="Semester Analytics" title="Semester Analytics" @if ($active === 'semester') aria-current="page" @endif><x-icon name="trending-up" class="tab-icon icon-18" /><span class="tab-full">Semester Analytics</span><span class="tab-short" aria-hidden="true">Semester</span></a>
            <a class="screen-tab-btn" href="{{ url('/roster') }}" aria-label="Class Roster &amp; Settings" title="Class Roster &amp; Settings" @if ($active === 'roster') aria-current="page" @endif><x-icon name="sliders" class="tab-icon icon-18" /><span class="tab-full">Class Roster &amp; Settings</span><span class="tab-short" aria-hidden="true">Roster</span></a>
        </nav>

        @if ($currentClass)
            <ul class="header-chips" aria-label="Today's attendance, {{ $currentClass->name }}" title="Snapshot for {{ \Carbon\CarbonImmutable::parse($todaySnapshot['date'])->format('l, M j') }}">
                <li class="header-chip"><strong>{{ $todaySnapshot['enrolled'] }}</strong> Enrolled</li>
                <li class="header-chip header-chip-present"><strong>{{ $todaySnapshot['present'] }}</strong> Present</li>
                <li class="header-chip header-chip-absent"><strong>{{ $todaySnapshot['absent'] }}</strong> Absent</li>
            </ul>
        @endif

        <div class="nav-right">
            <x-theme-switch />
            @isset($actions)
                {{ $actions }}
            @else
                <button type="button" class="btn-undo" disabled title="Nothing to undo here" aria-label="Undo (nothing to undo here)"><x-icon name="undo" class="icon-16" /><span class="btn-label">Undo</span></button>
            @endisset
            <div class="today-block" title="{{ $headerToday['weekday'] }}, {{ $headerToday['date'] }}">
                <span class="today-label">Today</span>
                <time class="today-date" datetime="{{ $headerToday['iso'] }}"><span class="today-weekday">{{ $headerToday['short'] }},</span> {{ $headerToday['date'] }}</time>
            </div>
            @if ($headerAccount)
                <div class="account-menu" data-account-menu>
                    <button type="button" class="avatar-btn" data-account-toggle aria-haspopup="menu" aria-expanded="false" aria-controls="account-menu" aria-label="Account menu" title="Account"><span aria-hidden="true">{{ $headerAccount['initials'] }}</span></button>
                    <div class="account-popover glass glass-strong" id="account-menu" role="menu" aria-label="Account" hidden>
                        <p class="account-email"><span class="account-label">Signed in as</span><span class="account-address">{{ $headerAccount['email'] }}</span></p>
                        <a class="account-item" role="menuitem" href="{{ url('/roster') }}#account" data-account-link><x-icon name="user" class="icon-16" /><span>Account settings</span></a>
                        <form method="POST" action="{{ url('/logout') }}" class="logout-form">
                            @csrf
                            <button type="submit" class="account-item" role="menuitem"><x-icon name="log-out" class="icon-16" /><span>Log out</span></button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </header>
    @if ($printTitle !== null || $printLabel !== null)
        <x-print-header :title="$printTitle" :label="$printLabel" :school-class="$currentClass" />
    @endif
    <main id="main" class="page-main {{ $mainClass }}" {{ $attributes->only(['data-api', 'data-day-version']) }}>
        {{ $slot }}
    </main>
    <footer class="app-footer">
        <div class="footer-left">
            @isset($status)
                {{ $status }}
            @endisset
        </div>
        <span class="footer-center">ClassPulse · Student Participation Tracker</span>
        <div class="footer-right">
            @isset($shortcuts)
                {{ $shortcuts }}
            @endisset
        </div>
    </footer>
</body>
</html>
