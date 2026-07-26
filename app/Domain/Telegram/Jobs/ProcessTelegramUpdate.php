<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Jobs;

use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Telegram\Actions\HandleIncomingUpdate;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramUpdate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Everything the webhook controller refused to do inline.
 *
 * @see docs/04-telegram-layer.md §3
 */
final class ProcessTelegramUpdate extends TenantAwareJob
{
    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [5, 30];

    public function __construct(
        int $academyId,
        public readonly int $botId,
        public readonly int $updateId,
    ) {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.telegram_in', 'telegram-in'));
    }

    public function handle(HandleIncomingUpdate $action): void
    {
        $update = TelegramUpdate::query()
            ->where('telegram_bot_id', $this->botId)
            ->where('update_id', $this->updateId)
            ->first();

        if (! $update instanceof TelegramUpdate || $update->processed_at !== null) {
            return;
        }

        $bot = TelegramBot::query()->find($this->botId);

        if (! $bot instanceof TelegramBot) {
            return;
        }

        try {
            $action->handle($bot, $update->payload);
            $update->markProcessed();
        } catch (Throwable $e) {
            $update->markFailed($e->getMessage());

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('Telegram update processing failed permanently.', [
            'academy_id' => $this->academyId,
            'bot_id' => $this->botId,
            'update_id' => $this->updateId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return array_merge(parent::tags(), ['bot:'.$this->botId]);
    }
}
