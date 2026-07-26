<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

use App\Domain\Commerce\Models\DiscountCode;

/**
 * Outcome of applying a code — never throws for an invalid code, because "this
 * code expired" is a normal thing for a student to type into a bot.
 */
final readonly class DiscountResult
{
    private function __construct(
        public bool $valid,
        public int $originalAmount,
        public int $discountAmount,
        public int $finalAmount,
        public string $currency = 'IRR',
        public ?DiscountCode $code = null,
        public ?string $reason = null,
    ) {}

    public static function applied(DiscountCode $code, int $originalAmount, int $discountAmount, string $currency): self
    {
        $discountAmount = max(0, min($discountAmount, $originalAmount));

        return new self(
            true,
            $originalAmount,
            $discountAmount,
            $originalAmount - $discountAmount,
            $currency,
            $code,
        );
    }

    public static function rejected(int $originalAmount, string $reason, string $currency = 'IRR'): self
    {
        return new self(false, $originalAmount, 0, $originalAmount, $currency, null, $reason);
    }

    public function message(): string
    {
        return $this->valid
            ? __('billing.discount.applied')
            : __('billing.discount.'.($this->reason ?? 'invalid'));
    }
}
