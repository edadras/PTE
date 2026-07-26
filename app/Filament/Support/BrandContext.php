<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Models\AcademyBrand;
use App\Domain\Tenancy\Models\AcademyDomain;
use App\Domain\Tenancy\Services\BrandResolver;
use App\Domain\Tenancy\TenantContext;
use Throwable;

/**
 * Resolves the academy whose branding the panel chrome should wear.
 *
 * Filament boots the panel (and therefore evaluates `colors()`) inside the
 * `panel:{id}` middleware, which runs *before* ResolveTenantFromDomain. So the
 * panel cannot rely on TenantContext being populated yet — it has to be able to
 * answer "whose panel is this" from the Host header alone, and it must survive
 * the login page of an unknown host without throwing.
 *
 * @see docs/03-white-label.md §4.1
 */
final class BrandContext
{
    private static ?Academy $resolved = null;

    private static bool $attempted = false;

    /** Platform fallback so an unresolved host still renders something sane. */
    private const FALLBACK_PALETTE = [
        'primary' => '#2563EB',
        'secondary' => '#7C3AED',
        'accent' => '#7C3AED',
        'success' => '#16A34A',
        'danger' => '#DC2626',
    ];

    public static function academy(): ?Academy
    {
        if (TenantContext::check()) {
            return TenantContext::get();
        }

        if (self::$attempted) {
            return self::$resolved;
        }

        self::$attempted = true;

        return self::$resolved = self::resolveFromHost();
    }

    public static function brand(): ?AcademyBrand
    {
        $academy = self::academy();

        if (! $academy instanceof Academy) {
            return null;
        }

        try {
            return app(BrandResolver::class)->resolve($academy);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    public static function palette(): array
    {
        return self::brand()?->colorPalette() ?? self::FALLBACK_PALETTE;
    }

    public static function displayName(): string
    {
        return self::brand()?->display_name
            ?? self::academy()?->name
            ?? (string) config('pte.platform.name', 'PTE Platform');
    }

    public static function logoUrl(bool $dark = false): ?string
    {
        try {
            return self::brand()?->logoUrl($dark);
        } catch (Throwable) {
            return null;
        }
    }

    public static function iconUrl(): ?string
    {
        try {
            return self::brand()?->iconUrl();
        } catch (Throwable) {
            return null;
        }
    }

    public static function fontFamily(): string
    {
        $font = self::brand()?->font_family;

        return is_string($font) && $font !== '' ? $font : 'Inter';
    }

    /** `light` | `dark` | `auto` — drives the forced theme in the head hook. */
    public static function darkModePreference(): string
    {
        return self::brand()?->dark_mode?->value ?? 'auto';
    }

    /** Tests and long-running workers must not inherit a stale academy. */
    public static function flush(): void
    {
        self::$resolved = null;
        self::$attempted = false;
    }

    private static function resolveFromHost(): ?Academy
    {
        $request = request();

        try {
            $hostname = AcademyDomain::normalizeHostname($request->getHost());
        } catch (Throwable) {
            return null;
        }

        if ($hostname === '') {
            return null;
        }

        try {
            $domain = AcademyDomain::query()->forHostname($hostname)->first();

            if (! $domain instanceof AcademyDomain) {
                return null;
            }

            return Academy::query()->whereKey($domain->academy_id)->first();
        } catch (Throwable) {
            // The panel must still render when the database is unreachable or
            // the schema has not been migrated yet.
            return null;
        }
    }
}
