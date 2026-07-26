<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Reporting\Support\CsvWriter;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Full data export for one academy: one CSV per table plus a manifest.
 *
 * This is the GDPR portability artefact (docs/01 §8) and the input to the
 * per-academy restore drill (docs/10 §6), which means it has to be genuinely
 * restorable rather than merely informative. Three things make it so:
 *
 *  - **every** tenant table is walked, discovered from the schema rather than
 *    from a hand-maintained list — a list is guaranteed to go stale the first
 *    time a context adds a table, and the miss is silent;
 *  - tables are ordered by the migration that created them, so replaying the
 *    CSVs in manifest order never violates a foreign key;
 *  - the manifest records the schema version and a row count per table, so a
 *    restore can be verified instead of hoped for.
 *
 * The academy's own row is exported too, under `academies.csv`; without it the
 * archive describes a tenant that does not exist.
 */
final class AcademyArchiver
{
    /** Tables that mention an academy but are not the academy's own data. */
    private const EXCLUDED = [
        'platform_audit_logs',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
        'sessions',
    ];

    private const TENANT_KEY = 'academy_id';

    private const NULL_MARKER = '\\N';

    /**
     * @return array<string, mixed> the manifest, with `archive_path` added
     */
    public function archive(Academy $academy, ?string $destination = null): array
    {
        $workDir = $this->workDirectory($academy);

        try {
            $manifest = $this->writeTables($academy, $workDir);
            $destination = $destination ?? $this->defaultDestination($academy);

            File::ensureDirectoryExists(dirname($destination));

            $manifest['archive_path'] = $destination;

            File::put(
                $workDir.'/manifest.json',
                (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );

            $this->zip($workDir, $destination);

            $manifest['archive_bytes'] = (int) (filesize($destination) ?: 0);

            return $manifest;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Every table carrying an `academy_id`, in the order their migrations
     * created them — which is also a safe insertion order on restore.
     *
     * @return array<int, string>
     */
    public function tenantTables(): array
    {
        $tables = array_values(array_filter(
            $this->allTables(),
            fn (string $table): bool => ! in_array($table, self::EXCLUDED, true)
                && Schema::hasColumn($table, self::TENANT_KEY),
        ));

        $order = $this->creationOrder();

        usort($tables, static fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        return $tables;
    }

    public function schemaVersion(): string
    {
        try {
            $latest = DB::table('migrations')->orderByDesc('id')->value('migration');
        } catch (Throwable) {
            return 'unknown';
        }

        return is_string($latest) ? $latest : 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    private function writeTables(Academy $academy, string $workDir): array
    {
        $academyId = (int) $academy->getKey();
        $tables = [];

        // The academy row itself, keyed by primary key rather than academy_id.
        $tables['academies'] = $this->dump(
            'academies',
            $workDir.'/academies.csv',
            DB::table('academies')->where('id', $academyId),
        );

        foreach ($this->tenantTables() as $table) {
            $tables[$table] = $this->dump(
                $table,
                $workDir.'/'.$table.'.csv',
                DB::table($table)->where(self::TENANT_KEY, $academyId),
            );
        }

        return [
            'format' => 'pte-academy-export/1',
            'schema_version' => $this->schemaVersion(),
            'generated_at' => now()->toIso8601String(),
            'platform' => (string) config('pte.platform.name'),
            'academy' => [
                'id' => $academyId,
                'slug' => (string) $academy->slug,
                'name' => (string) $academy->name,
                'status' => $academy->status->value,
                'timezone' => $academy->timezone,
            ],
            'csv' => [
                'delimiter' => ',',
                'enclosure' => '"',
                'encoding' => 'UTF-8 with BOM',
                'line_ending' => "\r\n",
                'null_marker' => self::NULL_MARKER,
            ],
            'total_rows' => array_sum(array_column($tables, 'rows')),
            // Insertion order for a restore: parents before children.
            'restore_order' => array_keys($tables),
            'tables' => $tables,
            'notes' => [
                'Restore by inserting each CSV in the order given by restore_order.',
                'A cell equal to \\N is SQL NULL; an empty cell is an empty string.',
                'Primary keys are preserved; restoring into a populated database requires remapping them.',
                'Files stored on the tenant disk are NOT included — object storage is exported separately.',
            ],
        ];
    }

    /**
     * @param  Builder  $query
     * @return array<string, mixed>
     */
    private function dump(string $table, string $path, $query): array
    {
        $columns = Schema::getColumnListing($table);
        $writer = new CsvWriter($path);
        $writer->headers($columns);

        if (in_array('id', $columns, true)) {
            $query->orderBy('id');
        }

        // cursor() streams: an academy with a million answer rows must not have
        // to fit in the exporting process's memory.
        foreach ($query->cursor() as $row) {
            $values = (array) $row;

            $writer->write(array_map(
                // Distinguishing NULL from '' matters for a restore: a nullable
                // score of NULL means "not graded", an empty string means bad
                // data. \N is MySQL's own convention for it in a text dump.
                static fn (string $column): mixed => ($values[$column] ?? null) === null
                    ? self::NULL_MARKER
                    : $values[$column],
                $columns,
            ));
        }

        $rows = $writer->rowCount();
        $writer->close();

        return [
            'file' => basename($path),
            'rows' => $rows,
            'columns' => $columns,
        ];
    }

    private function zip(string $workDir, string $destination): void
    {
        $zip = new ZipArchive;

        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create the archive at [{$destination}].");
        }

        foreach (File::files($workDir) as $file) {
            $zip->addFile($file->getPathname(), $file->getFilename());
        }

        $zip->close();
    }

    /**
     * @return array<int, string>
     */
    private function allTables(): array
    {
        // Unqualified: SQLite reports `main.students` and MySQL `db.students`,
        // and neither form can be handed back to hasColumn() or to a query.
        return array_values(array_filter(
            Schema::getTableListing(schemaQualified: false),
            static fn (string $table): bool => $table !== '',
        ));
    }

    /**
     * Table name => position of the migration that created it.
     *
     * @return array<string, int>
     */
    private function creationOrder(): array
    {
        $order = [];

        try {
            $migrations = DB::table('migrations')->orderBy('id')->pluck('migration');
        } catch (Throwable) {
            return $order;
        }

        foreach ($migrations as $index => $migration) {
            if (preg_match('/create_(.+)_table$/', (string) $migration, $matches) === 1) {
                $order[$matches[1]] ??= $index;
            }
        }

        return $order;
    }

    private function workDirectory(Academy $academy): string
    {
        $dir = storage_path('app/tmp/academy-export-'.$academy->getKey().'-'.Str::random(8));

        File::ensureDirectoryExists($dir);

        return $dir;
    }

    private function defaultDestination(Academy $academy): string
    {
        return storage_path(sprintf(
            'app/exports/academy-%s-%s.zip',
            $academy->slug,
            now()->format('Ymd-His'),
        ));
    }
}
