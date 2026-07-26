<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Jobs;

use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Telegram\Enums\BroadcastStatus;
use App\Domain\Telegram\Events\BroadcastFinished;
use App\Domain\Telegram\Models\Broadcast;

/**
 * Closes a broadcast once its batch drains.
 *
 * A separate job rather than work inside the batch's `then` callback: that
 * callback runs outside any tenant context, and touching a tenant-scoped model
 * from there would throw.
 */
final class CompleteBroadcast extends TenantAwareJob
{
    public int $tries = 3;

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

        $broadcast->forceFill([
            'status' => BroadcastStatus::Completed,
            'finished_at' => now(),
        ])->save();

        BroadcastFinished::dispatch($broadcast);
    }
}
