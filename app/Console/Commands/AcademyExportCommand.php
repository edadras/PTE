<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAcademy;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Reporting\Services\AcademyArchiver;
use Illuminate\Console\Command;
use Throwable;

/**
 * Full, restorable data export for one academy.
 *
 * Both a GDPR portability obligation (docs/01 §8) and the input to the
 * per-academy restore drill (docs/10 §6). Always audited on the platform log —
 * an operator taking a complete copy of a customer's data is exactly the kind
 * of action that has to be accountable.
 *
 * @see docs/10-infrastructure-and-ops.md §8
 */
final class AcademyExportCommand extends Command
{
    use ResolvesAcademy;

    protected $signature = 'academy:export
        {id : Academy id or slug}
        {--path= : Destination .zip path (defaults to storage/app/exports)}
        {--json : Print the manifest as JSON}';

    protected $description = 'Export every row belonging to an academy as a ZIP of per-table CSVs plus a manifest.';

    public function handle(AcademyArchiver $archiver, AuditRecorder $audit): int
    {
        $academy = $this->requireAcademy($this->argument('id'));

        if ($academy === null) {
            return self::FAILURE;
        }

        $path = $this->option('path');
        $path = is_string($path) && $path !== '' ? $path : null;

        try {
            $manifest = $archiver->archive($academy, $path);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $audit->recordPlatform(AuditAction::AcademyExported, $academy, [
            'archive_path' => $manifest['archive_path'],
            'schema_version' => $manifest['schema_version'],
            'total_rows' => $manifest['total_rows'],
            'tables' => count($manifest['tables']),
        ]);

        if ($this->option('json') === true) {
            $this->line((string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info(__('reports.console.academy_exported', [
            'name' => (string) $academy->name,
            'path' => (string) $manifest['archive_path'],
        ]));

        $rows = [];

        foreach ($manifest['tables'] as $table => $meta) {
            if ((int) $meta['rows'] > 0) {
                $rows[] = [$table, $meta['rows']];
            }
        }

        $this->table(['Table', 'Rows'], $rows);

        $this->components->twoColumnDetail(__('reports.console.schema_version'), (string) $manifest['schema_version']);
        $this->components->twoColumnDetail(__('reports.console.total_rows'), (string) $manifest['total_rows']);

        return self::SUCCESS;
    }
}
