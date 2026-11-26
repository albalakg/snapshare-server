<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            Route::prefix('api/auth')
                ->middleware('throttle:100,10', 'guest')
                ->group(base_path('routes/groups/auth.php'));

            Route::prefix('api/user')
                ->middleware('auth:api')
                ->group(base_path('routes/groups/user.php'));

            Route::prefix('api/events')
                ->group(base_path('routes/groups/event.php'));

            Route::prefix('api/events')
                ->group(base_path('routes/groups/event_whatsapp.php'));

            Route::prefix('api/events')
                ->group(base_path('routes/groups/event_guests.php'));

            Route::prefix('api/events')
                ->group(base_path('routes/groups/event_seating.php'));

            Route::prefix('api/events')
                ->group(base_path('routes/groups/event_messaging.php'));

            Route::prefix('api/events')
                ->group(base_path('routes/groups/event_icebreaker.php'));

            Route::prefix('api/events')
                ->group(base_path('routes/groups/event_hub.php'));

            Route::prefix('api/events')
                ->group(base_path('routes/groups/event_trivia.php'));

            Route::prefix('api/public')
                ->group(base_path('routes/groups/public.php'));

            Route::prefix('api/public')
                ->group(base_path('routes/groups/messaging_public.php'));

            Route::prefix('api/store')
                ->group(base_path('routes/groups/store.php'));

            Route::prefix('api/subscriptions')
                ->group(base_path('routes/groups/subscription.php'));

            Route::prefix('api/discount-codes')
                ->group(base_path('routes/groups/discount_code.php'));

            Route::middleware('api')
                ->group(base_path('routes/groups/openwa_webhook.php'));

            Route::middleware('api')
                ->group(base_path('routes/groups/messaging_webhook.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('google-auth-code', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Public RSVP (WhatsApp messaging). Named limiters so each endpoint has its own bucket per IP
        // (plain throttle:N,M shares one bucket per IP across every route that uses it).
        RateLimiter::for('rsvp-read', fn (Request $request) => Limit::perMinute(60)->by('rsvp-read|' . $request->ip()));
        RateLimiter::for('rsvp-submit', fn (Request $request) => Limit::perMinute(20)->by('rsvp-submit|' . $request->ip()));
        RateLimiter::for('rsvp-hub-submit', fn (Request $request) => Limit::perMinute(10)->by('rsvp-hub|' . $request->ip()));
    }
}
