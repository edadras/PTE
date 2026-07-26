<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Telegram\Events\BotTokenRotated;

/**
 * Mandatory audit (docs/02 §7): a bot credential being replaced.
 *
 * Only the masked suffix is recorded — AuditRecorder would redact a token
 * anyway, but not putting it in the payload in the first place means one fewer
 * place it could ever be logged.
 */
final class RecordBotTokenRotation
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(BotTokenRotated $event): void
    {
        $bot = $event->bot;

        $this->recorder->record(
            AuditAction::BotTokenRotated,
            $bot,
            [],
            [
                'bot_user_id' => $bot->bot_user_id,
                'username' => $bot->username,
                'token_last4' => $bot->token_last4,
            ],
            (int) $bot->academy_id,
        );
    }
}
