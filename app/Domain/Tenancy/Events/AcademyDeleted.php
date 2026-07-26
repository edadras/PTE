<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Academy;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Soft deletion (docs/01 §8) — the academy row still exists when listeners run,
 * inside its 30 day retention window.
 */
final class AcademyDeleted
{
    use Dispatchable;

    public function __construct(public readonly Academy $academy) {}
}
