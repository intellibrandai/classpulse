<?php

namespace App\Providers;

use App\Services\TodaySnapshot;
use App\Support\Initials;
use App\Support\SchoolCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SchoolCalendar::class, fn () => new SchoolCalendar(config('classpulse.school_timezone')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Header chips: the layout receives $todaySnapshot (see docs/api-notes.md).
        View::composer('components.layouts.app', function ($view) {
            $today = CarbonImmutable::parse(app(SchoolCalendar::class)->today());
            $user = auth()->user();

            $view->with([
                'todaySnapshot' => app(TodaySnapshot::class)->for($view->getData()['currentClass'] ?? null),
                'headerToday' => ['weekday' => $today->format('l'), 'short' => $today->format('D'), 'date' => $today->format('M j, Y'), 'iso' => $today->toDateString()],
                'headerAccount' => $user === null ? null : ['email' => (string) $user->email, 'initials' => Initials::of($user->name, $user->email)],
            ]);
        });

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip())
            ->response(fn (Request $request, array $headers) => response()->view('auth.login', [
                'throttleSeconds' => $headers['Retry-After'] ?? 60,
            ], 429, $headers)));
    }
}
