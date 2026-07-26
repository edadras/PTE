<?php

declare(strict_types=1);

namespace App\Domain\Integration\Support;

use App\Domain\Identity\Models\ApiKey;
use App\Domain\Identity\Models\Student;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The named limiters the API routes reference, in one place.
 *
 * `throttle:api` on an undefined limiter silently degrades to "0 attempts per
 * minute" rather than failing loudly, so these are registered from both the
 * service provider and the route files — whichever boots first wins, and the
 * routes keep working even before the provider is wired up.
 *
 * @see docs/08-api-and-integrations.md §4 · docs/12-security-and-compliance.md §7
 */
final class ApiRateLimiters
{
    /**
     * Deliberately not latched: the RateLimiter is a per-application singleton
     * and a test suite builds a new application per test, so a static "already
     * done" flag would leave every request after the first one throttled to
     * zero attempts. Re-registering is a handful of closures.
     */
    public static function register(): void
    {
        // Academy API — 600/min per API key (docs/08 §4). Falls back to the
        // authenticated user, then the IP, so the platform tier is covered too.
        RateLimiter::for('api', static function (Request $request): Limit {
            $apiKey = $request->attributes->get('api_key');

            if ($apiKey instanceof ApiKey) {
                return Limit::perMinute(600)->by('ak:'.$apiKey->getKey());
            }

            $user = $request->user();
            $userId = $user instanceof Model ? $user->getKey() : null;

            return $userId === null
                ? Limit::perMinute(60)->by('ip:'.$request->ip())
                : Limit::perMinute(600)->by('user:'.$userId);
        });

        // Student API — 120/min per student.
        RateLimiter::for('student-api', static function (Request $request): Limit {
            $student = $request->attributes->get('student');

            return $student instanceof Student
                ? Limit::perMinute(120)->by('st:'.$student->getKey())
                : Limit::perMinute(30)->by('ip:'.$request->ip());
        });

        // Direct media upload — 20/min.
        RateLimiter::for('media-upload', static function (Request $request): Limit {
            $student = $request->attributes->get('student');

            return $student instanceof Student
                ? Limit::perMinute(20)->by('st:'.$student->getKey())
                : Limit::perMinute(5)->by('ip:'.$request->ip());
        });

        // Credential endpoints — 5/min per IP + identifier (docs/12 §7).
        RateLimiter::for('login', static function (Request $request): Limit {
            $identifier = (string) ($request->input('email')
                ?? $request->input('phone')
                ?? $request->input('code')
                ?? '');

            return Limit::perMinute(5)->by($request->ip().'|'.sha1($identifier));
        });
    }
}
