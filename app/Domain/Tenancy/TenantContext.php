<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Exceptions\TenantNotResolvedException;
use App\Domain\Tenancy\Models\Academy;
use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\PermissionRegistrar;

/**
 * The single source of truth for "which academy are we acting as right now".
 *
 * Everything tenant-aware reads from here: the global query scope, Redis key
 * prefixes, the storage disk root, the permission team id and the active locale.
 *
 * Keeping this one indirection means the application never knows whether a
 * tenant lives on the shared database or on a dedicated connection — which is
 * what keeps the door to database-per-tenant open for Enterprise customers.
 *
 * @see docs/01-multi-tenancy.md
 */
final class TenantContext
{
    private static ?Academy $academy = null;

    /** Connection name that was active before a tenant with its own database was set. */
    private static ?string $previousConnection = null;

    private static ?string $previousCachePrefix = null;

    private static ?string $previousLocale = null;

    public static function set(Academy $academy): void
    {
        self::$previousConnection ??= (string) Config::get('database.default');
        self::$previousCachePrefix ??= (string) Config::get('cache.prefix');
        self::$previousLocale ??= App::getLocale();

        self::$academy = $academy;

        // Enterprise escape hatch: a tenant may live on its own connection.
        if (filled($academy->database_connection)) {
            Config::set('database.default', $academy->database_connection);
        }

        Config::set('cache.prefix', self::cachePrefix($academy));
        Config::set('filesystems.disks.tenant.root', "academies/{$academy->getKey()}");

        // spatie/laravel-permission runs in team mode; the team is the academy.
        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($academy->getKey());
        }

        if (filled($locale = $academy->preferredLocale())) {
            App::setLocale($locale);
        }
    }

    public static function get(): ?Academy
    {
        return self::$academy;
    }

    public static function check(): bool
    {
        return self::$academy instanceof Academy;
    }

    public static function require(): Academy
    {
        return self::$academy ?? throw TenantNotResolvedException::forOperation('TenantContext::require');
    }

    public static function id(): int
    {
        return self::require()->getKey();
    }

    /** Tenant id or null — for code paths that legitimately run outside a tenant. */
    public static function idOrNull(): ?int
    {
        return self::$academy?->getKey();
    }

    public static function forget(): void
    {
        if (self::$previousConnection !== null) {
            Config::set('database.default', self::$previousConnection);
        }

        if (self::$previousCachePrefix !== null) {
            Config::set('cache.prefix', self::$previousCachePrefix);
        }

        if (self::$previousLocale !== null) {
            App::setLocale(self::$previousLocale);
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }

        self::$academy = null;
        self::$previousConnection = null;
        self::$previousCachePrefix = null;
        self::$previousLocale = null;
    }

    /**
     * Run a callback inside a tenant, then restore whatever was active before.
     *
     * This is the only sanctioned way for console commands, schedulers and
     * platform-level jobs to touch tenant data.
     *
     * @template TReturn
     *
     * @param  Closure():TReturn  $callback
     * @return TReturn
     */
    public static function runFor(Academy $academy, Closure $callback): mixed
    {
        $previous = self::$academy;

        try {
            self::set($academy);

            return $callback();
        } finally {
            self::forget();

            if ($previous instanceof Academy) {
                self::set($previous);
            }
        }
    }

    /**
     * Run a callback with no tenant at all. Tenant-scoped queries will throw.
     *
     * @template TReturn
     *
     * @param  Closure():TReturn  $callback
     * @return TReturn
     */
    public static function runWithout(Closure $callback): mixed
    {
        $previous = self::$academy;

        try {
            self::forget();

            return $callback();
        } finally {
            if ($previous instanceof Academy) {
                self::set($previous);
            }
        }
    }

    public static function cachePrefix(Academy $academy): string
    {
        return 'ac'.$academy->getKey().':';
    }
}
