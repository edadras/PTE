<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Events;

use App\Domain\Telegram\Models\Broadcast;
use Illuminate\Foundation\Events\Dispatchable;

final class BroadcastFinished
{
    use Dispatchable;

    public function __construct(public readonly Broadcast $broadcast) {}
}
