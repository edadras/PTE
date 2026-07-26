<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Events;

use App\Domain\Commerce\Data\ProfitabilitySnapshot;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The alarm that stops us discovering a loss at month end (docs/09 §6).
 */
final class AcademyUnprofitable
{
    use Dispatchable;

    public function __construct(public readonly ProfitabilitySnapshot $snapshot) {}
}
