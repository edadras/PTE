<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Jobs;

use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Telegram\Data\OutgoingMessage;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Models\Broadcast;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Services\MessageSender;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One outbound message, with the whole failure taxonomy from docs/04 §7:
 *
 *   429  → release for exactly retry_after seconds
 *   403  → the student blocked the bot; stop, do not retry
 *   400  → chat not found; stop, do not retry
 *   5xx  → exponential backoff, five attempts
 */
final class SendTelegramMessage extends TenantAwareJob
{
    public int $tries = 5;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 60, 300];

    /**
     * @param  array<string, mixed>  $message  serialised OutgoingMessage
     */
    public function __construct(
        int $academyId,
        public readonly array $message,
        public readonly ?int $botId = null,
    ) {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.telegram_out', 'telegram-out'));
    }

    public function handle(MessageSender $sender): void
    {
        $bot = $this->botId === null
            ? TelegramBot::query()->active()->first()
            : TelegramBot::query()->find($this->botId);

        if (! $bot instanceof TelegramBot) {
            return;
        }

        $message = OutgoingMessage::fromArray($this->message);

        if ($message->chatId === 0) {
            return;
        }

        // Spread the load rather than hammering the limiter: if this chat or bot
        // is saturated, come back when the window has moved.
        $wait = $sender->availableIn($bot, $message->chatId);

        if ($wait > 0) {
            $this->release($wait);

            return;
        }

        try {
            $sender->send($bot, $message);
            $this->countBroadcast($message, 'sent');
        } catch (TelegramApiException $e) {
            $this->handleApiFailure($e, $message);
        }
    }

    private function handleApiFailure(TelegramApiException $e, OutgoingMessage $message): void
    {
        if ($e->isRateLimited()) {
            $this->release(max(1, $e->retryAfter ?? 5));

            return;
        }

        if ($e->isForbidden()) {
            $this->countBroadcast($message, 'blocked');
            $this->delete();

            return;
        }

        if ($e->isChatNotFound() || ! $e->isServerError()) {
            $this->countBroadcast($message, 'failed');
            $this->delete();

            return;
        }

        // 5xx and transport errors: let the queue's backoff handle it.
        throw $e;
    }

    private function countBroadcast(OutgoingMessage $message, string $counter): void
    {
        if ($message->broadcastId === null) {
            return;
        }

        $broadcast = Broadcast::query()->find($message->broadcastId);

        $broadcast?->recordOutcome($counter);
    }

    public function failed(Throwable $e): void
    {
        $message = OutgoingMessage::fromArray($this->message);

        $this->countBroadcast($message, 'failed');

        Log::warning('Telegram message could not be delivered.', [
            'academy_id' => $this->academyId,
            'chat_id' => $message->chatId,
            'error' => $e->getMessage(),
        ]);
    }
}
