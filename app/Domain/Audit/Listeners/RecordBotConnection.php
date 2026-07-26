<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Telegram\Events\BotConnected;

/**
 * Mandatory audit (docs/02 §7): a bot token going live.
 *
 * Only the masked suffix is recorded — AuditRecorder would redact the token
 * anyway, but not putting it in the payload in the first place means one fewer
 * place it could ever be logged.
 */
final class RecordBotConnection
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(BotConnected $event): void
    {
        $bot = $event->bot;

        $this->recorder->record(
            AuditAction::BotConnected,
            $bot,
            [],
            [
                'bot_user_id' => $bot->bot_user_id,
                'username' => $bot->username,
                'token_last4' => $bot->token_last4,
                'webhook_url' => $bot->webhook_url,
            ],
            (int) $bot->academy_id,
        );
    }
}
