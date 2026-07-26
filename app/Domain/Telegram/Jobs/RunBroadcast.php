<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Jobs;

use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Telegram\Data\OutgoingMessage;
use App\Domain\Telegram\Enums\BroadcastStatus;
use App\Domain\Telegram\Events\BroadcastFinished;
use App\Domain\Telegram\Models\Broadcast;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramIdentity;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fans a broadcast out into a Bus batch of per-recipient send jobs.
 *
 * At ~30 messages/second, ten thousand students take about six minutes, so this
 * has to be observable and interruptible: the batch gives live sent/failed/
 * blocked counters and a cancel button, and blocked recipients are excluded up
 * front rather than discovered one 403 at a time.
 *
 * @see docs/04-telegram-layer.md §7
 */
final class RunBroadcast extends TenantAwareJob
{
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(int $academyId, public readonly int $broadcastId)
    {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.telegram_out', 'telegram-out'));
    }

    public function handle(): void
    {
        $broadcast = Broadcast::query()->find($this->broadcastId);

        if (! $broadcast instanceof Broadcast || $broadcast->status->isFinished()) {
            return;
        }

        $bot = TelegramBot::query()->active()->first();

        if (! $bot instanceof TelegramBot) {
            $broadcast->forceFill([
                'status' => BroadcastStatus::Failed,
                'last_error' => 'No active Telegram bot for this academy.',
                'finished_at' => now(),
            ])->save();

            return;
        }

        $recipients = $this->recipients($broadcast);
        $total = (clone $recipients)->count();

        $broadcast->forceFill([
            'status' => BroadcastStatus::Running,
            'started_at' => now(),
            'total' => $total,
        ])->save();

        if ($total === 0) {
            $this->finish($broadcast);

            return;
        }

        $academyId = $this->academyId;
        $broadcastId = $this->broadcastId;
        $template = $this->template($broadcast);
        $botId = (int) $bot->getKey();

        $batch = Bus::batch([])
            ->name("broadcast:{$broadcastId}")
            ->allowFailures()
            ->onQueue((string) config('pte.queues.telegram_out', 'telegram-out'))
            ->then(static function (Batch $batch) use ($academyId, $broadcastId): void {
                CompleteBroadcast::dispatch($academyId, $broadcastId);
            })
            ->catch(static function (Batch $batch, Throwable $e) use ($broadcastId): void {
                Log::error('Broadcast batch failed.', [
                    'broadcast_id' => $broadcastId,
                    'error' => $e->getMessage(),
                ]);
            })
            ->dispatch();

        $chunkSize = (int) config('pte.telegram.broadcast_chunk_size', 100);

        $recipients->chunkById($chunkSize, function (Collection $identities) use ($batch, $broadcast, $academyId, $template, $botId): void {
            if ($broadcast->fresh()?->isCancelled() === true) {
                $batch->cancel();

                return;
            }

            $jobs = $identities->map(
                static fn (TelegramIdentity $identity): SendTelegramMessage => new SendTelegramMessage(
                    $academyId,
                    $template->forChat((int) $identity->chat_id, $identity->student_id)->toArray(),
                    $botId,
                )
            )->all();

            $batch->add($jobs);
        });

        $broadcast->forceFill(['batch_id' => $batch->id])->save();
    }

    /**
     * @return Builder<TelegramIdentity>
     */
    private function recipients(Broadcast $broadcast): Builder
    {
        $query = TelegramIdentity::query()->reachable();

        $filter = $broadcast->audience_filter ?? [];

        if (($filter['registered_only'] ?? false) === true) {
            $query->whereNotNull('student_id');
        }

        if (is_array($filter['student_ids'] ?? null) && $filter['student_ids'] !== []) {
            $query->whereIn('student_id', $filter['student_ids']);
        }

        if (is_string($filter['language_code'] ?? null)) {
            $query->where('language_code', $filter['language_code']);
        }

        return $query;
    }

    private function template(Broadcast $broadcast): OutgoingMessage
    {
        $content = $broadcast->content;

        $text = (string) ($content['text'] ?? '');
        $photo = $content['photo'] ?? null;

        $message = is_string($photo) && $photo !== ''
            ? OutgoingMessage::photo(0, $photo, $text)
            : OutgoingMessage::text(0, $text);

        return new OutgoingMessage(
            chatId: 0,
            text: $message->text,
            kind: $message->kind,
            file: $message->file,
            replyMarkup: is_array($content['reply_markup'] ?? null) ? $content['reply_markup'] : null,
            parseMode: is_string($content['parse_mode'] ?? null) ? $content['parse_mode'] : 'HTML',
            broadcastId: (int) $broadcast->getKey(),
        );
    }

    private function finish(Broadcast $broadcast): void
    {
        $broadcast->forceFill([
            'status' => BroadcastStatus::Completed,
            'finished_at' => now(),
        ])->save();

        BroadcastFinished::dispatch($broadcast);
    }
}
