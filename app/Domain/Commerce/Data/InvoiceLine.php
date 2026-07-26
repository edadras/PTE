<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

final readonly class InvoiceLine
{
    /**
     * @param  int  $unitPrice  Minor units.
     * @param  int  $total  Minor units — quantity × unitPrice, less any discount.
     */
    public function __construct(
        public string $description,
        public int $quantity,
        public int $unitPrice,
        public int $total,
        public ?string $periodLabel = null,
    ) {}

    public static function of(string $description, int $unitPrice, int $quantity = 1, ?string $periodLabel = null): self
    {
        return new self($description, $quantity, $unitPrice, $unitPrice * $quantity, $periodLabel);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'total' => $this->total,
            'period_label' => $this->periodLabel,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['description'] ?? ''),
            (int) ($data['quantity'] ?? 1),
            (int) ($data['unit_price'] ?? 0),
            (int) ($data['total'] ?? 0),
            isset($data['period_label']) ? (string) $data['period_label'] : null,
        );
    }
}
