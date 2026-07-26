<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Integration\Support\ApiRateLimiters;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Every named limiter a route references must exist here.
 *
 * `throttle:name` on an undefined limiter degrades to zero attempts per minute
 * rather than failing loudly, so a missing definition looks like a total
 * outage of that endpoint. Registering from the provider is what makes them
 * survive `route:cache`, which never evaluates the route files.
 *
 * Keys are tenant or caller, never IP where a better key exists — all of
 * Telegram's traffic arrives from a handful of addresses, so an IP key would
 * let one busy academy throttle every other academy's bot.
 *
 * @see docs/12-security-and-compliance.md §7
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        ApiRateLimiters::register();

        RateLimiter::for('telegram-webhook', static fn (Request $request): Limit => Limit::perMinute(600)
            ->by((string) $request->route('botPublicId')));

        // One student must not be able to burn the academy's whole monthly AI
        // allowance in an afternoon.
        RateLimiter::for('ai-per-student', static function (Request $request): Limit {
            $student = $request->attributes->get('student');
            $academyId = TenantContext::idOrNull() ?? 0;

            return Limit::perHour(60)->by(
                $academyId.'|'.($student?->getKey() ?? $request->ip() ?? 'unknown')
            );
        });
    }
}
