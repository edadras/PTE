<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\AcademyUserRole;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired only for an *active* grant — an invitation confers nothing until it is
 * accepted, so it is announced as StaffInvited instead.
 */
final class RoleChanged
{
    use Dispatchable;

    public function __construct(public readonly AcademyUserRole $membership) {}
}
