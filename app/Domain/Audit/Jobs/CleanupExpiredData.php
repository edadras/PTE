<?php

declare(strict_types=1);

namespace App\Domain\Audit\Jobs;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Audit\Services\RetentionSweeper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Nightly retention sweep.
 *
 * A platform job with no tenant: it iterates academies itself where it has to
 * (media, report files) and prunes the shared log tables in one pass (docs/10
 * §2). Runs on the lowest-priority queue — nothing waits on it.
 */
final class CleanupExpiredData implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly bool $dryRun = false)
    {
        $this->onQueue((string) config('pte.queues.maintenance', 'maintenance'));
    }

    /**
     * @return array<string, int>
     */
    public function handle(RetentionSweeper $sweeper, AuditRecorder $audit): array
    {
        $result = $sweeper->sweep(dryRun: $this->dryRun);

        Log::info('Retention sweep finished.', $result + ['dry_run' => $this->dryRun]);

        if (! $this->dryRun && array_sum($result) > 0) {
            // Platform-level only, and not mirrored into tenants: writing a row
            // into every academy's trail on every nightly run would drown the
            // entries that matter.
            $audit->recordPlatform(AuditAction::RetentionSweep, null, $result, mirrorToTenant: false);
        }

        return $result;
    }
}
