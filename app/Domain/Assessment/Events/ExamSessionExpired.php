<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use App\Domain\Assessment\Models\ExamSession;
use Illuminate\Foundation\Events\Dispatchable;

final class ExamSessionExpired
{
    use Dispatchable;

    public function __construct(public readonly ExamSession $session) {}
}
