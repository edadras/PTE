<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Actions;

use App\Domain\Telegram\Enums\BroadcastStatus;
use App\Domain\Telegram\Jobs\RunBroadcast;
use App\Domain\Telegram\Models\Broadcast;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use RuntimeException;

/**
 * Queues a broadcast, now or at a scheduled time.
 *
 * Plan limits (Starter 1/month, Professional 10, Enterprise unlimited) are
 * enforced by the Commerce context via the `broadcast.send` gate — checked here
 * so the owner is stopped before ten thousand jobs exist.
 *
 * @see docs/04-telegram-layer.md §7
 */
final class StartBroadcast
{
    public function handle(Broadcast $broadcast): Broadcast
    {
        if ($broadcast->status->isFinished()) {
            throw new RuntimeException('This broadcast has already finished.');
        }

        if (! TelegramBot::query()->active()->exists()) {
            throw new RuntimeException('This academy has no connected Telegram bot.');
        }

        if ($broadcast->scheduled_at !== null && $broadcast->scheduled_at->isFuture()) {
            $broadcast->forceFill(['status' => BroadcastStatus::Scheduled])->save();

            RunBroadcast::dispatch($broadcast->academy_id, (int) $broadcast->getKey())
                ->delay($broadcast->scheduled_at);

            return $broadcast;
        }

        $broadcast->forceFill(['status' => BroadcastStatus::Running])->save();

        RunBroadcast::dispatch($broadcast->academy_id, (int) $broadcast->getKey());

        return $broadcast;
    }

    /**
     * Stop a running broadcast mid-flight.
     *
     * Cancelling the Bus batch drops every queued send; jobs already picked up
     * by a worker still complete, which is why the counters keep moving for a
     * few seconds after the button is pressed.
     */
    public function cancel(Broadcast $broadcast): Broadcast
    {
        if (! $broadcast->status->canBeCancelled()) {
            throw new RuntimeException('This broadcast cannot be cancelled.');
        }

        if (filled($broadcast->batch_id)) {
            $batch = Bus::findBatch((string) $broadcast->batch_id);

            if ($batch instanceof Batch) {
                $batch->cancel();
            }
        }

        $broadcast->forceFill([
            'status' => BroadcastStatus::Cancelled,
            'finished_at' => now(),
        ])->save();

        return $broadcast;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $audienceFilter
     */
    public function create(
        string $title,
        array $content,
        array $audienceFilter = [],
        ?\DateTimeInterface $scheduledAt = null,
        ?int $userId = null,
    ): Broadcast {
        return Broadcast::query()->create([
            'academy_id' => TenantContext::id(),
            'title' => $title,
            'content' => $content,
            'audience_filter' => $audienceFilter,
            'scheduled_at' => $scheduledAt,
            'status' => $scheduledAt === null ? BroadcastStatus::Draft : BroadcastStatus::Scheduled,
            'created_by' => $userId,
        ]);
    }
}
