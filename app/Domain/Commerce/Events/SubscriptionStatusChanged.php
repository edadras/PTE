<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Events;

use App\Domain\Commerce\Enums\SubscriptionStatus;
use App\Domain\Commerce\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The single hook the rest of the platform needs in order to react to billing:
 * flip the panel to read-only, change the bot's greeting, send an email.
 */
final class SubscriptionStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly SubscriptionStatus $from,
        public readonly SubscriptionStatus $to,
    ) {}
}
