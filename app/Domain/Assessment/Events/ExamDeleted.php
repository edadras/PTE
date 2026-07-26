<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use App\Domain\Assessment\Models\Exam;
use Illuminate\Foundation\Events\Dispatchable;

final class ExamDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly Exam $exam,
        public readonly ?int $deletedBy = null,
    ) {}
}
