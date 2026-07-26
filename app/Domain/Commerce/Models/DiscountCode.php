<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $academy_id
 * @property string $type 'percentage' (value = basis points) | 'fixed' (value = minor units)
 *
 * @see docs/09-billing-and-plans.md §5
 */
final class DiscountCode extends Model
{
    use BelongsToAcademy;
    use SoftDeletes;

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'max_discount_amount' => 'integer',
            'min_order_amount' => 'integer',
            'max_redemptions' => 'integer',
            'redemptions_count' => 'integer',
            'per_student_limit' => 'integer',
            'is_active' => 'boolean',
            'meta' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return HasMany<DiscountRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    public function isPercentage(): bool
    {
        return $this->type === self::TYPE_PERCENTAGE;
    }

    public function isWithinWindow(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return ($this->starts_at === null || $this->starts_at->lessThanOrEqualTo($at))
            && ($this->expires_at === null || $this->expires_at->greaterThan($at));
    }

    public function hasRedemptionsLeft(): bool
    {
        return $this->max_redemptions === null || $this->redemptions_count < $this->max_redemptions;
    }

    /**
     * Discount for an order, in minor units. Percentage codes work in basis
     * points so a 12.5% code stays exact without touching a float.
     */
    public function discountFor(int $amount): int
    {
        $discount = $this->isPercentage()
            ? intdiv($amount * $this->value + 5_000, 10_000)
            : $this->value;

        if ($this->max_discount_amount !== null) {
            $discount = min($discount, $this->max_discount_amount);
        }

        return max(0, min($discount, $amount));
    }

    /** @param  Builder<DiscountCode>  $query */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q): Builder => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q): Builder => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
