<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Commerce\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => Str::slug(fake()->unique()->words(2, true)),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            // Minor units of IRR.
            'price_monthly' => 2_500_000,
            'price_yearly' => 25_000_000,
            'currency' => 'IRR',
            'limits' => [
                'active_students' => 100,
                'staff_users' => 3,
                'questions' => 500,
                'ai_requests' => 1_000,
                'ai_tokens' => null,
                'ai_cost_usd' => 6_000,
                'asr_minutes' => 300,
                'storage_mb' => 5_120,
                'broadcasts' => 1,
            ],
            'features' => [
                'flow_builder' => false,
                'custom_domain' => false,
                'byok' => false,
            ],
            'is_public' => true,
            'sort_order' => 10,
            'is_active' => true,
        ];
    }

    /**
     * @param  array<string, int|null>  $limits
     */
    public function withLimits(array $limits): static
    {
        return $this->state(fn (array $attributes): array => [
            'limits' => array_merge($attributes['limits'] ?? [], $limits),
        ]);
    }

    public function unlimited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'limits' => array_map(static fn (mixed $v): null => null, $attributes['limits'] ?? []),
        ]);
    }

    public function enterprise(): static
    {
        return $this->state(fn (): array => [
            'key' => 'enterprise',
            'name' => 'Enterprise',
            'price_monthly' => null,
            'price_yearly' => null,
        ]);
    }
}
