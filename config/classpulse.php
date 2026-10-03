<?php

return [
    'school_timezone' => env('APP_TIMEZONE', 'America/Toronto'),
    // The ONE place for the active-students limit per class. Every cap, rule, message, factory and seed reads it.
    'max_roster' => 35,
    'max_points' => 99,
    'import_max_kb' => 256,
    // Shortest password accepted by the classpulse:* commands and by Account settings (the single definition).
    'password_min_length' => 12,
    // Failed Account-settings attempts allowed per user and IP before a 429, and the window in seconds.
    'account_max_attempts' => 5,
    'account_decay_seconds' => 60,
];
