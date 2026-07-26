<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Support;

use InvalidArgumentException;

/**
 * Money is integer minor units, always. Never a float — a rounding error in a
 * ledger is a bug you find months later in a bank reconciliation.
 *
 * For IRR the minor unit is the Rial itself (the currency has no subunit in
 * practice); for USD/EUR it is the cent.
 */
final readonly class Money
{
    public function __construct(
        public int $minorUnits,
        public string $currency = 'IRR',
    ) {}

    public static function of(int $minorUnits, string $currency = 'IRR'): self
    {
        return new self($minorUnits, $currency);
    }

    public static function zero(string $currency = 'IRR'): self
    {
        return new self(0, $currency);
    }

    public static function scaleFor(string $currency): int
    {
        return match (strtoupper($currency)) {
            'IRR', 'IRT', 'JPY', 'KRW', 'VND' => 0,
            default => 2,
        };
    }

    public function plus(self $other): self
    {
        return new self($this->minorUnits + $other->assertSameCurrency($this)->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        return new self($this->minorUnits - $other->assertSameCurrency($this)->minorUnits, $this->currency);
    }

    /** Percentage in basis points, rounded half-up in integer space. */
    public function percentage(int $basisPoints): self
    {
        return new self(intdiv($this->minorUnits * $basisPoints + 5_000, 10_000), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function toMajorString(): string
    {
        $scale = self::scaleFor($this->currency);

        if ($scale === 0) {
            return number_format($this->minorUnits, 0, '.', ',');
        }

        return number_format($this->minorUnits / (10 ** $scale), $scale, '.', ',');
    }

    public function format(): string
    {
        return $this->toMajorString().' '.__('billing.currency.'.strtolower($this->currency));
    }

    private function assertSameCurrency(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }

        return $this;
    }
}
