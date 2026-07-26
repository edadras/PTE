<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\BillingCycle;
use App\Domain\Commerce\Enums\PaymentGatewayKey;
use App\Domain\Commerce\Enums\SubscriptionStatus;
use App\Domain\Commerce\Support\Money;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $academy_id
 * @property SubscriptionStatus $status
 * @property BillingCycle $billing_cycle
 * @property int $price Minor units of $currency.
 * @property CarbonInterface|null $current_period_end
 *
 * @see docs/09-billing-and-plans.md §3
 */
final class Subscription extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /** Days in past_due before the academy is suspended (docs/09 §3). */
    public const PAST_DUE_GRACE_DAYS = 7;

    /** Days after suspension before data deletion becomes permissible. */
    public const DATA_RETENTION_DAYS = 30;

    /** Days before renewal on which we remind (docs/09 §3). */
    public const REMINDER_DAYS = [7, 3, 1];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_cycle' => BillingCycle::class,
            'price' => 'integer',
            'auto_renew' => 'boolean',
            'reminders_sent' => 'array',
            'meta' => 'array',
            'started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'past_due_at' => 'datetime',
            'suspended_at' => 'datetime',
            'canceled_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return MorphMany<Payment, $this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function gatewayKey(): ?PaymentGatewayKey
    {
        return $this->gateway === null ? null : PaymentGatewayKey::tryFrom($this->gateway);
    }

    public function money(): Money
    {
        return new Money($this->price, $this->currency);
    }

    /**
     * The bot keeps serving students through past_due — they are not the ones
     * who forgot to pay (docs/09 §3).
     */
    public function allowsBotTraffic(): bool
    {
        return $this->status->allowsBotTraffic();
    }

    /** past_due and worse make the panel read-only. */
    public function allowsPanelWrites(): bool
    {
        return $this->status->allowsPanelWrites();
    }

    public function isOnTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing;
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    public function hasExpiredPeriod(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->current_period_end !== null && $this->current_period_end->lessThanOrEqualTo($at);
    }

    public function daysUntilRenewal(?CarbonInterface $at = null): ?int
    {
        if ($this->current_period_end === null) {
            return null;
        }

        $at ??= now();

        return (int) ceil($at->diffInDays($this->current_period_end, false));
    }

    public function suspendableAt(): ?CarbonInterface
    {
        return $this->past_due_at?->copy()->addDays(self::PAST_DUE_GRACE_DAYS);
    }

    public function purgeableAt(): ?CarbonInterface
    {
        return $this->suspended_at?->copy()->addDays(self::DATA_RETENTION_DAYS);
    }

    public function hasSentReminder(int $days): bool
    {
        return in_array($days, array_map('intval', $this->reminders_sent ?? []), true);
    }

    public function markReminderSent(int $days): void
    {
        $sent = array_map('intval', $this->reminders_sent ?? []);
        $sent[] = $days;

        $this->forceFill(['reminders_sent' => array_values(array_unique($sent))])->save();
    }

    /** @param  Builder<Subscription>  $query */
    public function scopeWithStatus(Builder $query, SubscriptionStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(static fn (SubscriptionStatus $s): string => $s->value, $statuses));
    }

    /** @param  Builder<Subscription>  $query */
    public function scopeLive(Builder $query): Builder
    {
        return $this->scopeWithStatus(
            $query,
            SubscriptionStatus::Trialing,
            SubscriptionStatus::Active,
            SubscriptionStatus::PastDue,
        );
    }
}
