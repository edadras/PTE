<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Shared\Support\TenantKey;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Models\AcademyBrand;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Reads a tenant's brand once per request/cache window.
 *
 * The panel, the bot and every rendered report ask for the brand on virtually
 * every interaction, so it is cached under the tenant-prefixed key and
 * invalidated whenever the brand is saved.
 *
 * @see docs/03-white-label.md §4.1
 */
final class BrandResolver
{
    /** @var array<int, AcademyBrand> */
    private array $memo = [];

    public function resolve(?Academy $academy = null): AcademyBrand
    {
        $academy ??= TenantContext::require();
        $id = $academy->getKey();

        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }

        $ttl = (int) config('pte.tenancy.cache_ttl', 3600);

        $brand = Cache::remember(
            self::cacheKey($id),
            $ttl,
            static fn (): ?AcademyBrand => $academy->brand()->first()
        );

        return $this->memo[$id] = $brand ?? $this->fallback($academy);
    }

    /**
     * @return array<string, string>
     */
    public function palette(?Academy $academy = null): array
    {
        return $this->resolve($academy)->colorPalette();
    }

    public function displayName(?Academy $academy = null): string
    {
        return $this->resolve($academy)->display_name;
    }

    public function forget(Academy|int $academy): void
    {
        $id = $academy instanceof Academy ? $academy->getKey() : $academy;

        unset($this->memo[$id]);

        Cache::forget(self::cacheKey($id));
    }

    public static function cacheKey(int $academyId): string
    {
        return TenantKey::for($academyId, 'brand');
    }

    /** An academy without a brand row still has to render something. */
    private function fallback(Academy $academy): AcademyBrand
    {
        $brand = new AcademyBrand([
            'display_name' => $academy->name,
            'short_name' => $academy->name,
            'default_locale' => 'fa',
        ]);

        $brand->academy_id = $academy->getKey();

        return $brand;
    }
}
