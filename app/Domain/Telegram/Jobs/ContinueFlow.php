<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Jobs;

use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramFlow;
use App\Domain\Telegram\Services\ConversationState;
use App\Domain\Telegram\Services\FlowEngine;

/**
 * Wakes a flow parked on a `delay` node.
 *
 * The state is re-read rather than carried in the payload: the student may have
 * typed /cancel during the delay, and a stale payload would happily resume a
 * conversation they already walked away from.
 */
final class ContinueFlow extends TenantAwareJob
{
    public int $tries = 3;

    public function __construct(
        int $academyId,
        public readonly int $flowId,
        public readonly int $chatId,
        public readonly string $nodeKey,
    ) {
        parent::__construct($academyId);
    }

    public function handle(FlowEngine $engine, ConversationState $state): void
    {
        // Cancelled or restarted in the meantime — nothing to resume.
        if ($state->flowId($this->chatId) !== $this->flowId) {
            return;
        }

        $flow = TelegramFlow::query()->find($this->flowId);
        $bot = TelegramBot::query()->active()->first();

        if (! $flow instanceof TelegramFlow || ! $bot instanceof TelegramBot) {
            return;
        }

        $engine->continueFrom($flow, $bot, $this->chatId, $this->nodeKey);
    }
}
