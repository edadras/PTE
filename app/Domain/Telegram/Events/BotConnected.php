<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Events;

use App\Domain\Telegram\Models\TelegramBot;
use Illuminate\Foundation\Events\Dispatchable;

final class BotConnected
{
    use Dispatchable;

    public function __construct(public readonly TelegramBot $bot) {}
}
