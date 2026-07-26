<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Telegram\Events\BotWebhookReset;

/**
 * Mandatory audit (docs/02 §7): a webhook reset — because "drop pending
 * updates" silently discards student messages, someone must be answerable for
 * having pressed it.
 */
final class RecordWebhookReset
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(BotWebhookReset $event): void
    {
        $bot = $event->bot;

        $this->recorder->record(
            AuditAction::BotWebhookReset,
            $bot,
            [],
            [
                'bot_user_id' => $bot->bot_user_id,
                'username' => $bot->username,
                'webhook_url' => $bot->webhook_url,
                'dropped_pending_updates' => $event->droppedPendingUpdates,
            ],
            (int) $bot->academy_id,
        );
    }
}
