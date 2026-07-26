<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Events;

use App\Domain\Commerce\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

final class SubscriptionCanceled
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly bool $immediately,
        public readonly ?string $reason = null,
    ) {}
}
