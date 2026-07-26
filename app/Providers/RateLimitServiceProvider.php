<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Every named limiter referenced by a route lives here.
 *
 * Limits are keyed by tenant or by caller, never by IP where a better key
 * exists — all of Telegram's traffic arrives from a handful of addresses, so
 * an IP-keyed limiter would have one busy academy throttling everyone else.
 *
 * @see docs/12-security-and-compliance.md §7
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('telegram-webhook', static fn (Request $request): Limit => Limit::perMinute(600)
            ->by((string) $request->route('botPublicId')));

        RateLimiter::for('api', static function (Request $request): Limit {
            $academyId = TenantContext::idOrNull();

            return $academyId !== null
                ? Limit::perMinute(600)->by("api:academy:{$academyId}")
                : Limit::perMinute(60)->by($request->ip() ?? 'unknown');
        });

        RateLimiter::for('student-api', static function (Request $request): Limit {
            $student = $request->attributes->get('student');

            return $student !== null
                ? Limit::perMinute(120)->by('student:'.$student->getKey())
                : Limit::perMinute(30)->by($request->ip() ?? 'unknown');
        });

        RateLimiter::for('media-upload', static function (Request $request): Limit {
            $student = $request->attributes->get('student');

            return Limit::perMinute(20)->by(
                $student !== null ? 'upload:'.$student->getKey() : ($request->ip() ?? 'unknown')
            );
        });

        // Keyed by credential as well as IP, so an attacker cannot lock a real
        // user out by spamming their email from elsewhere.
        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by(($request->ip() ?? 'unknown').'|'.(string) $request->input('email')));

        // One student must not be able to burn the whole academy's monthly AI
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
