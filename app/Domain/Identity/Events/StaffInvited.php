<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\AcademyUserRole;
use Illuminate\Foundation\Events\Dispatchable;

final class StaffInvited
{
    use Dispatchable;

    public function __construct(public readonly AcademyUserRole $membership) {}
}
