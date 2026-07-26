<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Events;

use App\Domain\Commerce\Data\QuotaResult;
use Illuminate\Foundation\Events\Dispatchable;

final class QuotaThresholdReached
{
    use Dispatchable;

    public function __construct(
        public readonly int $academyId,
        public readonly QuotaResult $result,
    ) {}
}
