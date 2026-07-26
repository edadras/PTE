<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use App\Domain\Commerce\Data\QuotaResult;

/**
 * Only for callers that explicitly opt into throwing. QuotaGuard itself never
 * throws for a quota outcome — a warning is not an error (docs/09 §2).
 */
final class QuotaExceededException extends CommerceException
{
    private function __construct(string $message, public readonly QuotaResult $result)
    {
        parent::__construct($message);
    }

    public static function from(QuotaResult $result): self
    {
        return new self($result->message(), $result);
    }
}
