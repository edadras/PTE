<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Events;

use App\Domain\Telegram\Enums\MenuActionType;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramIdentity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised when a student taps a menu item whose action belongs to another
 * context (practice, exams, courses, billing, support).
 *
 * An event rather than a direct call: the Telegram layer must not depend on
 * Assessment or Commerce, otherwise every change over there ripples into the
 * bot. Listeners live in the owning context.
 */
final class MenuActionInvoked
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly TelegramBot $bot,
        public readonly TelegramIdentity $identity,
        public readonly MenuActionType $action,
        public readonly array $payload,
        public readonly int $chatId,
    ) {}
}
