<?php

namespace App\Providers;

use App\Services\TenantContext;
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
        // One per request or job, never shared between them.
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Per phone number, to stay within Meta's Cloud API throughput.
        RateLimiter::for('whatsapp-send', fn (object $job) => Limit::perSecond(
            config('services.whatsapp.messages_per_second'),
        )->by('wa-send:'.$job->phoneNumberId));

        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('auth-email:'.$email.'|'.$request->ip()),
                Limit::perMinute(30)->by('auth-ip:'.$request->ip()),
            ];
        });
    }
}
