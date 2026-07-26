<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Tenancy\Models\Academy;
use App\Filament\Support\Notifications\AcademyImpersonated;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Super Admin support access, under the three conditions docs/02 §2 makes
 * non-negotiable: a hard 30 minute window, a permanent red banner, and an
 * audit entry plus a notification to the academy owner.
 *
 * The two panels live on different hostnames (platform on the root domain, an
 * academy on its own), so the session cookie cannot be shared. The handover is
 * therefore a short-lived *signed* URL — no shared cache or cookie needed, only
 * the application key — and it is single-use: the academy host burns the nonce
 * on arrival.
 */
final class Impersonation
{
    public const SESSION_KEY = 'pte.impersonation';

    public const DURATION_MINUTES = 30;

    /** How long the handover link itself stays valid. */
    private const HANDOVER_TTL_SECONDS = 120;

    private const NONCE_CACHE_PREFIX = 'impersonation:nonce:';

    public static function isActive(): bool
    {
        return self::payload() !== null;
    }

    /**
     * @return array{academy_id: int, actor_id: int, actor_name: string, started_at: string, expires_at: string}|null
     */
    public static function payload(): ?array
    {
        /** @var array<string, mixed>|null $payload */
        $payload = Session::get(self::SESSION_KEY);

        if (! is_array($payload) || ! isset($payload['expires_at'])) {
            return null;
        }

        /** @var array{academy_id: int, actor_id: int, actor_name: string, started_at: string, expires_at: string} $payload */
        return $payload;
    }

    public static function expiresAt(): ?Carbon
    {
        $payload = self::payload();

        return $payload === null ? null : Carbon::parse($payload['expires_at']);
    }

    public static function hasExpired(): bool
    {
        $expiresAt = self::expiresAt();

        return $expiresAt !== null && $expiresAt->isPast();
    }

    public static function minutesRemaining(): int
    {
        $expiresAt = self::expiresAt();

        if ($expiresAt === null || $expiresAt->isPast()) {
            return 0;
        }

        return (int) ceil(now()->diffInMinutes($expiresAt, false));
    }

    /**
     * Build the one-time handover link into the academy panel.
     *
     * The audit entry and the owner notification are written here, on the
     * platform side, because that is where the decision is actually taken —
     * a link that is generated but never followed is still an access attempt
     * the owner deserves to know about.
     */
    public static function handoverUrl(Academy $academy, User $actor): string
    {
        $nonce = (string) Str::uuid();

        $previousRoot = url('/');
        $host = self::hostFor($academy);
        $scheme = request()->isSecure() || app()->environment('production') ? 'https' : request()->getScheme();

        URL::forceRootUrl($scheme.'://'.$host);

        try {
            $url = URL::temporarySignedRoute(
                'filament.panel.impersonation.start',
                now()->addSeconds(self::HANDOVER_TTL_SECONDS),
                [
                    'academy' => $academy->getKey(),
                    'actor' => $actor->getKey(),
                    'nonce' => $nonce,
                ],
            );
        } finally {
            URL::forceRootUrl($previousRoot);
        }

        PlatformAudit::record(
            action: AuditAction::Impersonated,
            actor: $actor,
            target: $academy,
            payload: [
                'phase' => 'start',
                'nonce' => $nonce,
                'expires_at' => now()->addMinutes(self::DURATION_MINUTES)->toIso8601String(),
            ],
        );

        self::notifyOwner($academy, $actor);

        return $url;
    }

    /**
     * Consume a handover nonce. Returns false when it has already been used.
     */
    public static function burnNonce(string $nonce): bool
    {
        try {
            return Cache::add(self::NONCE_CACHE_PREFIX.$nonce, true, self::HANDOVER_TTL_SECONDS * 2);
        } catch (Throwable) {
            // A cache outage must not become a way to bypass the audit trail,
            // so a nonce that cannot be recorded is refused.
            return false;
        }
    }

    public static function begin(Academy $academy, User $actor): void
    {
        Session::put(self::SESSION_KEY, [
            'academy_id' => (int) $academy->getKey(),
            'actor_id' => (int) $actor->getKey(),
            'actor_name' => (string) $actor->name,
            'started_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(self::DURATION_MINUTES)->toIso8601String(),
        ]);
    }

    /** Ends the session and logs the actor out — support access is not a login. */
    public static function end(?string $reason = null): void
    {
        $payload = self::payload();

        if ($payload !== null) {
            PlatformAudit::record(
                action: AuditAction::Impersonated,
                actor: Auth::user() instanceof User ? Auth::user() : null,
                target: $payload['academy_id'],
                payload: [
                    'phase' => 'end',
                    'reason' => $reason ?? 'manual',
                    'started_at' => $payload['started_at'],
                ],
            );
        }

        Session::forget(self::SESSION_KEY);

        Auth::guard('web')->logout();
        Session::invalidate();
        Session::regenerateToken();
    }

    public static function hostFor(Academy $academy): string
    {
        return $academy->primaryDomain()?->hostname
            ?? $academy->slug.'.'.(string) config('pte.platform.root_domain');
    }

    private static function notifyOwner(Academy $academy, User $actor): void
    {
        $owner = $academy->owner;

        if (! $owner instanceof User) {
            return;
        }

        try {
            $owner->notify(new AcademyImpersonated($academy, $actor, self::DURATION_MINUTES));
        } catch (Throwable) {
            // Notification transport problems must never block support access.
        }
    }
}
