<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Support\Money;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Models\Course;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Carbon\CarbonInterface;
use Database\Factories\StudentSubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a student bought from an academy (B2C).
 *
 * @property int $academy_id
 * @property int $price Minor units of $currency.
 *
 * @see docs/09-billing-and-plans.md §5
 */
final class StudentSubscription extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<StudentSubscriptionFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELED = 'canceled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'discount_amount' => 'integer',
            'credit_amount' => 'integer',
            'duration_days' => 'integer',
            'auto_renew' => 'boolean',
            'entitlements' => 'array',
            'meta' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return HasMany<DiscountRedemption, $this> */
    public function discountRedemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    public function money(): Money
    {
        return new Money($this->price, $this->currency);
    }

    /** What the student actually owes after discount and referral credit. */
    public function payableAmount(): int
    {
        return max(0, $this->price - $this->discount_amount - $this->credit_amount);
    }

    public function isActive(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->status === self::STATUS_ACTIVE
            && ($this->starts_at === null || $this->starts_at->lessThanOrEqualTo($at))
            && ($this->expires_at === null || $this->expires_at->greaterThan($at));
    }

    public function entitlement(string $key, mixed $default = null): mixed
    {
        return $this->entitlements[$key] ?? $default;
    }

    /** @param  Builder<StudentSubscription>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(fn (Builder $q): Builder => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
