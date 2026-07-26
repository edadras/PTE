<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Events;

use App\Domain\Telegram\Data\DeepLinkPayload;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramIdentity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A /start, with or without a deep-link payload.
 *
 * The Identity context listens for this to create or link the Student and to
 * write the `student_acquisitions` row — the report that tells an academy which
 * channel a student actually came from (docs/04 §8).
 */
final class StudentStartedBot
{
    use Dispatchable;

    public function __construct(
        public readonly TelegramBot $bot,
        public readonly TelegramIdentity $identity,
        public readonly ?DeepLinkPayload $deepLink = null,
    ) {}
}
