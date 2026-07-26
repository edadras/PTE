<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Events;

use App\Domain\Commerce\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Suspension is the only billing state that silences the bot, so it is the one
 * the Telegram layer must listen for.
 */
final class AcademySuspended
{
    use Dispatchable;

    public function __construct(public readonly Subscription $subscription) {}
}
