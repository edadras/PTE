<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Data\OutgoingMessage;
use App\Domain\Telegram\Enums\MessageDirection;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Jobs\SendTelegramMessage;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramIdentity;
use App\Domain\Telegram\Models\TelegramMessage;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate-limited outbound messaging.
 *
 * Telegram allows ~30 messages/second per bot and 1 per second per private
 * chat. We sit a notch below both (config `pte.telegram.rate_limits`) because
 * being throttled costs more than being slightly slow: a 429 releases the job
 * back to the queue and the student waits longer than they would have.
 *
 * Because every academy has its own bot, these limits are per-tenant by
 * construction — one academy's broadcast cannot starve another's.
 *
 * @see docs/04-telegram-layer.md §7
 */
final class MessageSender
{
    public function __construct(private readonly TelegramClient $client) {}

    /**
     * Send now, respecting the rate limits.
     *
     * @return array<string, mixed> the Telegram message object
     *
     * @throws TelegramApiException
     */
    public function send(TelegramBot $bot, OutgoingMessage $message): array
    {
        $wait = $this->availableIn($bot, $message->chatId);

        if ($wait > 0) {
            throw new TelegramApiException(
                message: '[sendMessage] Local rate limit reached.',
                errorCode: 429,
                retryAfter: $wait,
                method: 'sendMessage',
            );
        }

        $this->hit($bot, $message->chatId);

        try {
            $result = $this->dispatchToApi($bot, $message);
        } catch (TelegramApiException $e) {
            $this->handleFailure($bot, $message, $e);

            throw $e;
        }

        $this->record($bot, $message, $result, 'sent');

        $bot->forceFill(['last_message_at' => now()])->saveQuietly();

        return $result;
    }

    /** Queue the message instead of sending inline. */
    public function queue(OutgoingMessage $message, ?int $academyId = null): void
    {
        SendTelegramMessage::dispatch($academyId ?? TenantContext::id(), $message->toArray())
            ->onQueue((string) config('pte.queues.telegram_out', 'telegram-out'));
    }

    /**
     * Seconds the caller must wait before this chat may be messaged again.
     * Zero means "go".
     */
    public function availableIn(TelegramBot $bot, int $chatId): int
    {
        $botLimit = (int) config('pte.telegram.rate_limits.per_bot_per_second', 25);
        $chatLimit = (int) config('pte.telegram.rate_limits.per_chat_per_second', 1);

        $wait = 0;

        foreach ([[$this->botKey($bot), $botLimit], [$this->chatKey($bot, $chatId), $chatLimit]] as [$key, $limit]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                // availableIn() rounds down and can report 0 while the window
                // is still closed; never tell the job to retry instantly or it
                // will spin against the limiter.
                $wait = max($wait, RateLimiter::availableIn($key), 1);
            }
        }

        return $wait;
    }

    /** One-second decay windows — these are per-second limits, not per-minute. */
    private function hit(TelegramBot $bot, int $chatId): void
    {
        RateLimiter::hit($this->botKey($bot), 1);
        RateLimiter::hit($this->chatKey($bot, $chatId), 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchToApi(TelegramBot $bot, OutgoingMessage $message): array
    {
        $client = $this->client->forBot($bot);

        $common = array_filter([
            'chat_id' => $message->chatId,
            'reply_markup' => $message->replyMarkup,
            'reply_to_message_id' => $message->replyToMessageId,
        ], static fn (mixed $value): bool => $value !== null);

        return match ($message->kind) {
            OutgoingMessage::KIND_PHOTO => $client->sendPhoto($common + array_filter([
                'photo' => $message->file,
                'caption' => $message->text !== '' ? $message->text : null,
                'parse_mode' => $message->parseMode,
            ], static fn (mixed $v): bool => $v !== null)),

            OutgoingMessage::KIND_AUDIO => $client->sendAudio($common + array_filter([
                'audio' => $message->file,
                'caption' => $message->text !== '' ? $message->text : null,
                'parse_mode' => $message->parseMode,
            ], static fn (mixed $v): bool => $v !== null)),

            OutgoingMessage::KIND_VOICE => $client->sendVoice($common + array_filter([
                'voice' => $message->file,
                'caption' => $message->text !== '' ? $message->text : null,
                'parse_mode' => $message->parseMode,
            ], static fn (mixed $v): bool => $v !== null)),

            default => $client->sendMessage($common + array_filter([
                'text' => $message->text,
                'parse_mode' => $message->parseMode,
                'disable_web_page_preview' => $message->disableWebPagePreview,
            ], static fn (mixed $v): bool => $v !== null)),
        };
    }

    /**
     * Translate an API failure into a durable fact about the recipient.
     *
     * 403 and "chat not found" are not transient: the student uninstalled
     * Telegram or blocked the bot, and every future send will fail the same
     * way. Marking the identity keeps broadcasts from burning quota on them.
     */
    private function handleFailure(TelegramBot $bot, OutgoingMessage $message, TelegramApiException $e): void
    {
        if ($e->isForbidden()) {
            $this->markBlocked($message->chatId);
            $this->record($bot, $message, [], 'blocked', $e->getMessage());

            return;
        }

        if ($e->isChatNotFound()) {
            $this->markBlocked($message->chatId);
            $this->record($bot, $message, [], 'failed', $e->getMessage());

            return;
        }

        // 429 and 5xx are retried by the job; not worth a history row each time.
        if (! $e->isRetryable()) {
            $this->record($bot, $message, [], 'failed', $e->getMessage());
        }
    }

    private function markBlocked(int $chatId): void
    {
        TelegramIdentity::query()
            ->where('chat_id', $chatId)
            ->get()
            ->each(static fn (TelegramIdentity $identity) => $identity->markBlocked());
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function record(
        TelegramBot $bot,
        OutgoingMessage $message,
        array $result,
        string $status,
        ?string $error = null,
    ): void {
        TelegramMessage::query()->create([
            'academy_id' => $bot->academy_id,
            'telegram_bot_id' => $bot->getKey(),
            'student_id' => $message->studentId,
            'chat_id' => $message->chatId,
            'direction' => MessageDirection::Out,
            'message_type' => $message->kind,
            'content' => mb_substr($message->text, 0, 4000),
            'telegram_message_id' => isset($result['message_id']) ? (int) $result['message_id'] : null,
            'status' => $status,
            'error' => $error,
            'broadcast_id' => $message->broadcastId,
            'created_at' => now(),
        ]);
    }

    private function botKey(TelegramBot $bot): string
    {
        return 'tg:bot:'.$bot->getKey();
    }

    private function chatKey(TelegramBot $bot, int $chatId): string
    {
        return 'tg:chat:'.$bot->getKey().':'.$chatId;
    }
}
