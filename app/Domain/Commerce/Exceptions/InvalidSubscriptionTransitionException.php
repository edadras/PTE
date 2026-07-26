<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use App\Domain\Commerce\Enums\SubscriptionStatus;

final class InvalidSubscriptionTransitionException extends CommerceException
{
    public static function between(SubscriptionStatus $from, SubscriptionStatus $to): self
    {
        return new self("A subscription cannot go from [{$from->value}] to [{$to->value}].");
    }
}
