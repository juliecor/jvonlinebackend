<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use App\Listeners\EmbedBrandLogo;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
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
        // Branded emails carry their logo inside the message instead of pointing at a web address.
        Event::listen(MessageSending::class, EmbedBrandLogo::class);

        // Per visitor (the real IP, see TrustFrontendClientIp), and per kind of request.
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('offer-view', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('offer-respond', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('invite-link', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('offer-upload', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        // Each question costs an OpenAI call: 20 a minute per person is plenty for a conversation.
        RateLimiter::for('assistant', fn (Request $r) => Limit::perMinute(20)->by($r->user()?->id ?: $r->ip()));
        // Voice: while someone talks, what they've said so far is written out every second and a half.
        RateLimiter::for('assistant-voice', fn (Request $r) => Limit::perMinute(90)->by($r->user()?->id ?: $r->ip()));
    }
}
