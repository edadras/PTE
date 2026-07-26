<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Data\InvoiceLine;
use App\Domain\Commerce\Support\Money;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $academy_id
 * @property string $number
 * @property int $sequence
 * @property int $total Minor units of $currency.
 *
 * @see docs/09-billing-and-plans.md §7
 */
final class Invoice extends Model
{
    use BelongsToAcademy;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
            'tax_rate_bp' => 'integer',
            'sequence' => 'integer',
            'lines' => 'array',
            'billing_details' => 'array',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return array<int, InvoiceLine>
     */
    public function lines(): array
    {
        return array_map(
            static fn (array $line): InvoiceLine => InvoiceLine::fromArray($line),
            $this->lines ?? [],
        );
    }

    public function totalMoney(): Money
    {
        return new Money($this->total, $this->currency);
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_ISSUED
            && $this->due_at !== null
            && $this->due_at->isPast();
    }
}
