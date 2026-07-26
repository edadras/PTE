<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAcademy;
use App\Domain\Reporting\Services\AcademyRestorer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Counterpart to academy:export — replays an export archive into a target
 * academy, the "restore one academy without touching the rest" drill of
 * docs/10 §6.
 *
 * Primary keys from the archive are never reused: every row is inserted under
 * a fresh id and every reference is rewritten, so the same archive restores
 * cleanly into an empty database or a busy shared one. The target academy row
 * itself is not modified — create it first (academy:create), then restore
 * into it.
 *
 * @see docs/10-infrastructure-and-ops.md §6 · §8
 */
final class AcademyRestoreCommand extends Command
{
    use ResolvesAcademy;

    protected $signature = 'academy:restore
        {id : Target academy id or slug}
        {backup : Path to a .zip produced by academy:export}
        {--dry-run : Validate the archive and report what would be inserted, writing nothing}
        {--force : Delete the target academy\'s existing rows and restore over them}
        {--json : Print the summary as JSON}';

    protected $description = 'Restore an academy:export archive into an academy, remapping every primary key.';

    public function handle(AcademyRestorer $restorer): int
    {
        $academy = $this->requireAcademy($this->argument('id'));

        if ($academy === null) {
            return self::FAILURE;
        }

        try {
            $summary = $restorer->restore(
                $academy,
                (string) $this->argument('backup'),
                dryRun: (bool) $this->option('dry-run'),
                force: (bool) $this->option('force'),
            );
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($summary);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function render(array $summary): void
    {
        if ($summary['dry_run'] === true) {
            $this->components->info(sprintf(
                'Dry run — nothing was written. The archive would insert %d row(s) into academy [%s].',
                (int) $summary['total_archived'],
                (string) $summary['target']['slug'],
            ));
        } else {
            $this->components->info(sprintf(
                'Restored %d row(s) into academy [%s].',
                (int) $summary['total_inserted'],
                (string) $summary['target']['slug'],
            ));
        }

        $rows = [];

        foreach ($summary['tables'] as $table => $counts) {
            if ((int) $counts['archived'] > 0) {
                $rows[] = [$table, $counts['archived'], $summary['dry_run'] === true ? '—' : $counts['inserted']];
            }
        }

        $this->table(['Table', 'Archived', 'Inserted'], $rows);

        foreach ($summary['purged'] as $table => $deleted) {
            $this->components->twoColumnDetail("Deleted from {$table} (--force)", (string) $deleted);
        }

        $this->components->twoColumnDetail('Archive schema', (string) $summary['archive']['schema_version']);
        $this->components->twoColumnDetail('Archive generated at', (string) $summary['archive']['generated_at']);
        $this->components->twoColumnDetail(
            'Source academy',
            sprintf('%s (id %d)', $summary['archive']['academy']['slug'], (int) $summary['archive']['academy']['id']),
        );
    }
}
