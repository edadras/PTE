<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\PaymentGatewayKey;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Support\Money;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\PaymentFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A payment attempt against a gateway.
 *
 * Card data is never present here — `meta` may hold a masked PAN the gateway
 * chose to return, nothing more (docs/09 §7).
 *
 * @property int $academy_id
 * @property int $amount Minor units of $currency.
 * @property PaymentStatus $status
 * @property PaymentGatewayKey $gateway
 */
final class Payment extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'gateway' => PaymentGatewayKey::class,
            'amount' => 'integer',
            'platform_fee_amount' => 'integer',
            'refunded_amount' => 'integer',
            'meta' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function money(): Money
    {
        return new Money($this->amount, $this->currency);
    }

    public function isPaid(): bool
    {
        return $this->status->isSettled();
    }

    public function refundableAmount(): int
    {
        return max(0, $this->amount - $this->refunded_amount);
    }

    /** Net of the platform commission we keep on B2C sales (docs/09 §5). */
    public function netToAcademy(): int
    {
        return max(0, $this->amount - $this->platform_fee_amount - $this->refunded_amount);
    }

    /** @param  Builder<Payment>  $query */
    public function scopePaid(Builder $query): Builder
    {
        return $query->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value]);
    }

    /** @param  Builder<Payment>  $query */
    public function scopePaidBetween(Builder $query, DateTimeInterface $from, DateTimeInterface $to): Builder
    {
        return $this->scopePaid($query)->whereBetween('paid_at', [$from, $to]);
    }
}
