<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use App\Domain\Tenancy\Enums\DarkMode;
use App\Domain\Tenancy\Services\BrandResolver;
use Database\Factories\AcademyBrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Everything a student ever sees. One row per academy.
 *
 * @property int $academy_id
 * @property string $display_name
 * @property DarkMode $dark_mode
 * @property array<int, string>|null $supported_locales
 *
 * @see docs/03-white-label.md
 */
final class AcademyBrand extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<AcademyBrandFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'dark_mode' => DarkMode::class,
            'supported_locales' => 'array',
        ];
    }

    /** The brand is read on nearly every request, so its cache must never go stale. */
    protected static function booted(): void
    {
        $forget = static function (self $brand): void {
            app(BrandResolver::class)->forget((int) $brand->academy_id);
        };

        self::saved($forget);
        self::deleted($forget);
    }

    /** Models live under app/Domain, so the default factory guesser misses. */
    protected static function newFactory(): AcademyBrandFactory
    {
        return AcademyBrandFactory::new();
    }

    public function logoUrl(bool $dark = false): ?string
    {
        $path = $dark
            ? ($this->logo_dark_path ?? $this->logo_light_path)
            : ($this->logo_light_path ?? $this->logo_dark_path);

        return $this->assetUrl($path);
    }

    public function iconUrl(): ?string
    {
        return $this->assetUrl($this->icon_path);
    }

    public function welcomeImageUrl(): ?string
    {
        return $this->assetUrl($this->welcome_image_path);
    }

    /**
     * @return array<string, string>
     */
    public function colorPalette(): array
    {
        return [
            'primary' => $this->primary_color ?? '#2563EB',
            'secondary' => $this->secondary_color ?? '#7C3AED',
            'accent' => $this->accent_color ?? $this->secondary_color ?? '#7C3AED',
            'success' => $this->success_color ?? '#16A34A',
            'danger' => $this->danger_color ?? '#DC2626',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function supportedLocales(): array
    {
        $locales = $this->supported_locales;

        if (! is_array($locales) || $locales === []) {
            return array_values(array_filter([$this->default_locale ?? 'fa']));
        }

        return array_values(array_unique(array_map(strval(...), $locales)));
    }

    public function supportsLocale(string $locale): bool
    {
        return in_array($locale, $this->supportedLocales(), true);
    }

    public function shortName(): string
    {
        return (string) ($this->short_name ?? $this->display_name);
    }

    /**
     * Tenant files are private; the bucket is never public, so links are
     * short-lived signed URLs where the driver supports them.
     */
    private function assetUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $disk = config('filesystems.disks.tenant') !== null
            ? 'tenant'
            : (string) config('filesystems.default');

        try {
            $storage = Storage::disk($disk);

            $minutes = (int) config('pte.media.signed_url_ttl_minutes', 15);

            return $storage->temporaryUrl($path, now()->addMinutes($minutes));
        } catch (Throwable) {
            // Local/public drivers cannot sign — a plain URL is fine there.
            try {
                return Storage::disk($disk)->url($path);
            } catch (Throwable) {
                return null;
            }
        }
    }
}
