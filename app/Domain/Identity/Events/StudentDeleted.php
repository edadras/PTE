<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\Student;
use Illuminate\Foundation\Events\Dispatchable;

final class StudentDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly Student $student,
        public readonly ?int $deletedBy = null,
    ) {}
}
