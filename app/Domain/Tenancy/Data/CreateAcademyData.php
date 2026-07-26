<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Data;

use App\Domain\Learning\Enums\ModuleKey;
use Illuminate\Support\Str;

/**
 * @see docs/01-multi-tenancy.md §8 · docs/03-white-label.md §9
 */
final readonly class CreateAcademyData
{
    /**
     * @param  array<int, ModuleKey|string>  $modules
     * @param  array<string, mixed>  $brand
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public ?string $ownerEmail = null,
        public ?string $ownerName = null,
        public ?int $ownerUserId = null,
        public string $locale = 'fa',
        public string $currency = 'IRR',
        public string $timezone = 'Asia/Tehran',
        public ?string $country = 'IR',
        public ?int $planId = null,
        public ?string $legalName = null,
        public array $modules = [],
        public array $brand = [],
        public array $settings = [],
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            name: (string) ($attributes['name'] ?? ''),
            slug: $attributes['slug'] ?? null,
            ownerEmail: $attributes['owner_email'] ?? null,
            ownerName: $attributes['owner_name'] ?? null,
            ownerUserId: isset($attributes['owner_user_id']) ? (int) $attributes['owner_user_id'] : null,
            locale: (string) ($attributes['locale'] ?? 'fa'),
            currency: (string) ($attributes['currency'] ?? 'IRR'),
            timezone: (string) ($attributes['timezone'] ?? 'Asia/Tehran'),
            country: $attributes['country'] ?? 'IR',
            planId: isset($attributes['plan_id']) ? (int) $attributes['plan_id'] : null,
            legalName: $attributes['legal_name'] ?? null,
            modules: $attributes['modules'] ?? [],
            brand: $attributes['brand'] ?? [],
            settings: $attributes['settings'] ?? [],
        );
    }

    public function resolvedSlug(): string
    {
        return Str::slug($this->slug ?? $this->name);
    }

    /**
     * @return array<int, string>
     */
    public function moduleKeys(): array
    {
        return array_values(array_unique(array_map(
            static fn (ModuleKey|string $module): string => $module instanceof ModuleKey ? $module->value : $module,
            $this->modules
        )));
    }
}
