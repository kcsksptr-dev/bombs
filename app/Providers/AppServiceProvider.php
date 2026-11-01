<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Limits that matter once the game is reachable online (`make public`). Players at one party
     * usually share an IP (the home Wi-Fi), so per-IP limits stay roomy; gameplay is limited per player.
     */
    private function configureRateLimiting(): void
    {
        // Creating guest players
        RateLimiter::for('guests', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // Creating, finding and joining rooms: also what someone guessing room codes would hammer
        RateLimiter::for('rooms', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Moves and lobby actions; page reloads after every broadcast count here too
        RateLimiter::for('play', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));
    }
}
