<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Events;

use App\Domain\Telegram\Models\TelegramBot;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Carries the bot row only — never the token itself. Listeners that need to
 * show *which* credential changed have `token_last4`.
 */
final class BotTokenRotated
{
    use Dispatchable;

    public function __construct(public readonly TelegramBot $bot) {}
}
