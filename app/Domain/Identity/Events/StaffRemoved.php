<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Tenancy\Models\Academy;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final class StaffRemoved
{
    use Dispatchable;

    /**
     * @param  array<int, string>  $roleNames  The roles held at the moment of removal —
     *                                         the membership rows are gone by the time a listener runs.
     */
    public function __construct(
        public readonly User $user,
        public readonly Academy $academy,
        public readonly array $roleNames,
        public readonly ?int $removedBy = null,
    ) {}
}
