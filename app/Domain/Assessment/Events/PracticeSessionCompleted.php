<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use App\Domain\Assessment\Models\PracticeSession;
use Illuminate\Foundation\Events\Dispatchable;

final class PracticeSessionCompleted
{
    use Dispatchable;

    public function __construct(public readonly PracticeSession $session) {}
}
