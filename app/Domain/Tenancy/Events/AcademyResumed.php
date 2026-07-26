<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Academy;
use Illuminate\Foundation\Events\Dispatchable;

final class AcademyResumed
{
    use Dispatchable;

    public function __construct(public readonly Academy $academy) {}
}
