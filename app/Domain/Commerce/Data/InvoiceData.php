<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

use Carbon\CarbonInterface;

/**
 * The structured invoice, independent of how it is rendered.
 */
final readonly class InvoiceData
{
    /**
     * @param  array<int, InvoiceLine>  $lines
     * @param  array<string, mixed>  $billingDetails
     */
    public function __construct(
        public int $academyId,
        public string $number,
        public int $sequence,
        public array $lines,
        public int $subtotal,
        public int $tax,
        public int $total,
        public int $taxRateBasisPoints,
        public string $currency,
        public ?CarbonInterface $issuedAt = null,
        public ?CarbonInterface $dueAt = null,
        public array $billingDetails = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'academy_id' => $this->academyId,
            'number' => $this->number,
            'sequence' => $this->sequence,
            'lines' => array_map(static fn (InvoiceLine $line): array => $line->toArray(), $this->lines),
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'total' => $this->total,
            'tax_rate_bp' => $this->taxRateBasisPoints,
            'currency' => $this->currency,
            'issued_at' => $this->issuedAt?->toIso8601String(),
            'due_at' => $this->dueAt?->toIso8601String(),
            'billing_details' => $this->billingDetails,
        ];
    }
}
