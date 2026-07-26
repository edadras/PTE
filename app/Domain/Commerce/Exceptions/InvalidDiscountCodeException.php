<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

final class InvalidDiscountCodeException extends CommerceException
{
    public static function withReason(string $code, string $reason): self
    {
        return new self("Discount code [{$code}] is not usable: {$reason}.");
    }
}
