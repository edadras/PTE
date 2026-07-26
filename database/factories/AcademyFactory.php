<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tenancy\Enums\AcademyStatus;
use App\Domain\Tenancy\Enums\DomainType;
use App\Domain\Tenancy\Enums\SslStatus;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Models\AcademyDomain;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Academy>
 */
final class AcademyFactory extends Factory
{
    protected $model = Academy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' Ltd',
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => AcademyStatus::Active,
            'timezone' => 'Asia/Tehran',
            'country' => 'IR',
            'database_connection' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => AcademyStatus::Suspended]);
    }

    public function onTrial(int $days = 14): static
    {
        return $this->state(fn (): array => ['trial_ends_at' => now()->addDays($days)]);
    }

    /** Academy with the rows a real tenant always has: settings, brand, domain. */
    public function configured(): static
    {
        return $this->afterCreating(function (Academy $academy): void {
            AcademySettingsFactory::new()->create(['academy_id' => $academy->getKey()]);
            AcademyBrandFactory::new()->create([
                'academy_id' => $academy->getKey(),
                'display_name' => $academy->name,
            ]);

            AcademyDomain::query()->create([
                'academy_id' => $academy->getKey(),
                'hostname' => $academy->slug.'.'.config('pte.platform.root_domain'),
                'type' => DomainType::Subdomain,
                'is_primary' => true,
                'ssl_status' => SslStatus::Issued,
                'verified_at' => now(),
            ]);
        });
    }
}
