<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\Jobs\CleanupExpiredData;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Audit\Services\RetentionSweeper;
use Illuminate\Console\Command;

/**
 * Manual entry point for the retention sweep.
 *
 * `--dry-run` counts without deleting, which is what anyone asking "how much
 * would this remove?" needs before a first run against a database that has
 * never been pruned.
 *
 * @see docs/10-infrastructure-and-ops.md §4
 */
final class CleanupExpiredDataCommand extends Command
{
    protected $signature = 'data:cleanup
        {--dry-run : Count what would be removed without removing it}
        {--queue : Dispatch the sweep to the maintenance queue instead of running inline}';

    protected $description = 'Prune telegram updates and messages, AI logs, activity logs, expired exports and old answer media.';

    public function handle(RetentionSweeper $sweeper, AuditRecorder $audit): int
    {
        $dryRun = $this->option('dry-run') === true;

        if ($this->option('queue') === true) {
            CleanupExpiredData::dispatch($dryRun);

            $this->components->info(__('reports.console.cleanup_queued'));

            return self::SUCCESS;
        }

        $result = (new CleanupExpiredData($dryRun))->handle($sweeper, $audit);

        $this->components->info(
            $dryRun ? __('reports.console.cleanup_dry_run') : __('reports.console.cleanup_done')
        );

        $this->table(
            ['Concern', 'Rows'],
            array_map(static fn (string $key, int $count): array => [$key, $count], array_keys($result), $result),
        );

        return self::SUCCESS;
    }
}
