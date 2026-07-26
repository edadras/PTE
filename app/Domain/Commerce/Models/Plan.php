<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\BillingCycle;
use App\Domain\Commerce\Enums\PlanKey;
use App\Domain\Commerce\Enums\UsageMetric;
use App\Domain\Commerce\Support\Money;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * NON-tenant: the plan catalogue is shared by the whole platform.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property int|null $price_monthly
 * @property int|null $price_yearly
 * @property string $currency
 * @property array<string, int|null> $limits
 * @property array<string, mixed> $features
 *
 * @see docs/09-billing-and-plans.md §1
 */
final class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'features' => 'array',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'price_monthly' => 'integer',
            'price_yearly' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function planKey(): ?PlanKey
    {
        return PlanKey::tryFrom($this->key);
    }

    public function label(): string
    {
        return $this->planKey()?->label() ?? $this->name;
    }

    /**
     * Null means unlimited. A metric absent from the plan is unlimited too —
     * we only ever meter what we deliberately decided to meter.
     */
    public function limitFor(UsageMetric|string $metric): ?int
    {
        $key = $metric instanceof UsageMetric ? $metric->value : $metric;
        $value = $this->limits[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) ($this->features[$feature] ?? false);
    }

    public function feature(string $feature, mixed $default = null): mixed
    {
        return $this->features[$feature] ?? $default;
    }

    /** Null price = negotiated (Enterprise), not free. */
    public function priceFor(BillingCycle $cycle): ?int
    {
        $value = $this->getAttribute($cycle->priceColumn());

        return $value === null ? null : (int) $value;
    }

    public function moneyFor(BillingCycle $cycle): ?Money
    {
        $price = $this->priceFor($cycle);

        return $price === null ? null : new Money($price, $this->currency);
    }

    /** @param  Builder<Plan>  $query */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->where('is_active', true)->orderBy('sort_order');
    }

    public static function findByKey(PlanKey|string $key): ?self
    {
        return self::query()->where('key', $key instanceof PlanKey ? $key->value : $key)->first();
    }
}
