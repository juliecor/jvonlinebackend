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
        // Per visitor (the real IP, see TrustFrontendClientIp), and per kind of request.
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('offer-view', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('offer-respond', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('invite-link', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('offer-upload', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
    }
}
