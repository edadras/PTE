<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Tenancy\Models\Academy;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Replays an academy:export archive into a target academy — the recovery path
 * docs/10 §6 calls the hardest and most likely scenario.
 *
 * The archive preserves the source database's primary keys, but the target
 * database may already be using them, so nothing is inserted verbatim:
 * every row gets a fresh id, and an old→new map per table rewrites every
 * reference as tables are replayed in the manifest's FK-safe restore_order.
 * References are found three ways, because the schema declares them three ways:
 *
 *  - real foreign keys, read from the live schema;
 *  - polymorphic pairs (`session_type`/`session_id`, `subject_type`/…), whose
 *    target table is resolved per row from the type value;
 *  - bare `*_id` columns that other contexts deliberately left unconstrained
 *    (docs/07), matched by pluralising the column stem against the archive's
 *    own table list.
 *
 * A reference into an archived table whose id is absent from the archive is a
 * hard error, not a warning: silently keeping the old id would attach the row
 * to whatever the target database happens to store under that key — in a
 * multi-tenant database that is a cross-tenant leak, which is the one failure
 * this platform never accepts. References to global tables (users, plans) are
 * kept as-is; those ids mean the same thing on both sides.
 */
final class AcademyRestorer
{
    private const NULL_MARKER = '\\N';

    /**
     * Telegram's own identifiers. They look like foreign keys (and
     * `telegram_message_id` even pluralises to a real table) but they belong
     * to Telegram's numbering, not ours — remapping them would corrupt data.
     */
    private const EXTERNAL_ID_COLUMNS = [
        'chat_id',
        'telegram_user_id',
        'telegram_message_id',
        'update_id',
        'bot_user_id',
    ];

    public function __construct(private readonly AcademyArchiver $archiver) {}

    /**
     * @return array<string, mixed> per-table summary of the (planned) restore
     */
    public function restore(Academy $target, string $archivePath, bool $dryRun = false, bool $force = false): array
    {
        if (! is_file($archivePath)) {
            throw new RuntimeException("There is no archive at [{$archivePath}].");
        }

        $zip = new ZipArchive;

        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("[{$archivePath}] is not a readable ZIP archive.");
        }

        try {
            $manifest = $this->manifest($zip, $archivePath);

            $this->assertSchemaMatches($manifest);
            $this->assertTablesRestorable($zip, $manifest);
            $this->assertRowCountsMatch($zip, $manifest);

            $occupied = $this->occupiedTables($target);

            if ($occupied !== [] && ! $force) {
                throw new RuntimeException(sprintf(
                    'Academy [%s] already has data in: %s. Pass --force to delete it and restore over it.',
                    $target->slug,
                    implode(', ', $occupied),
                ));
            }

            if ($dryRun) {
                return $this->summary($target, $manifest, inserted: [], purged: [], dryRun: true);
            }

            [$inserted, $purged] = DB::transaction(function () use ($zip, $manifest, $target, $force, $occupied): array {
                $purged = $force && $occupied !== [] ? $this->purgeExisting($target) : [];

                return [$this->replay($zip, $manifest, $target), $purged];
            });

            return $this->summary($target, $manifest, $inserted, $purged, dryRun: false);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(ZipArchive $zip, string $archivePath): array
    {
        $raw = $zip->getFromName('manifest.json');

        if ($raw === false) {
            throw new RuntimeException("[{$archivePath}] has no manifest.json — it is not an academy:export archive.");
        }

        $manifest = json_decode($raw, true);

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== 'pte-academy-export/1') {
            throw new RuntimeException(sprintf(
                'Unsupported archive format [%s]; this build restores [pte-academy-export/1].',
                is_array($manifest) ? (string) ($manifest['format'] ?? 'missing') : 'unreadable manifest',
            ));
        }

        $order = $manifest['restore_order'] ?? null;
        $tables = $manifest['tables'] ?? null;

        if (! is_array($order) || $order === [] || ! is_array($tables)) {
            throw new RuntimeException('The manifest names no tables to restore; the archive is corrupt.');
        }

        foreach ($order as $table) {
            if (! isset($tables[$table]['file'], $tables[$table]['rows'], $tables[$table]['columns'])) {
                throw new RuntimeException("The manifest lists [{$table}] in restore_order but describes no such table.");
            }
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertSchemaMatches(array $manifest): void
    {
        $live = $this->archiver->schemaVersion();
        $archived = (string) ($manifest['schema_version'] ?? 'unknown');

        if ($archived !== $live) {
            throw new RuntimeException(sprintf(
                'Schema mismatch: the archive was taken at [%s] but this database is at [%s]. '
                .'Restore on a database migrated to the matching version.',
                $archived,
                $live,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertTablesRestorable(ZipArchive $zip, array $manifest): void
    {
        foreach ($manifest['restore_order'] as $table) {
            $meta = $manifest['tables'][$table];

            if ($zip->locateName((string) $meta['file']) === false) {
                throw new RuntimeException("The archive is missing [{$meta['file']}] for table [{$table}].");
            }

            if (! Schema::hasTable($table)) {
                throw new RuntimeException("The archive contains [{$table}] but this database has no such table.");
            }

            $missing = array_diff((array) $meta['columns'], Schema::getColumnListing($table));

            if ($missing !== []) {
                throw new RuntimeException(sprintf(
                    'Table [%s] in this database is missing archived column(s): %s.',
                    $table,
                    implode(', ', $missing),
                ));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertRowCountsMatch(ZipArchive $zip, array $manifest): void
    {
        foreach ($manifest['restore_order'] as $table) {
            $meta = $manifest['tables'][$table];
            $actual = 0;

            foreach ($this->rows($zip, (string) $meta['file']) as $row) {
                $actual++;
            }

            if ($actual !== (int) $meta['rows']) {
                throw new RuntimeException(sprintf(
                    'The archive is corrupt: [%s] holds %d row(s) but the manifest promises %d for [%s].',
                    $meta['file'],
                    $actual,
                    (int) $meta['rows'],
                    $table,
                ));
            }
        }
    }

    /**
     * @return array<int, string> tenant tables that already hold rows for the target
     */
    private function occupiedTables(Academy $target): array
    {
        $occupied = [];

        foreach ($this->archiver->tenantTables() as $table) {
            if (DB::table($table)->where('academy_id', $target->getKey())->exists()) {
                $occupied[] = $table;
            }
        }

        return $occupied;
    }

    /**
     * Children before parents — the reverse of the manifest's insertion order —
     * so no delete ever trips a restricting foreign key.
     *
     * @return array<string, int> table => rows deleted
     */
    private function purgeExisting(Academy $target): array
    {
        $purged = [];

        foreach (array_reverse($this->archiver->tenantTables()) as $table) {
            $deleted = DB::table($table)->where('academy_id', $target->getKey())->delete();

            if ($deleted > 0) {
                $purged[$table] = $deleted;
            }
        }

        return $purged;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, int> table => rows inserted
     */
    private function replay(ZipArchive $zip, array $manifest, Academy $target): array
    {
        /** @var array<string, array<int, int>> $maps old id => new id, per table */
        $maps = [];
        $inserted = [];
        $archivedTables = array_values(array_filter(
            (array) $manifest['restore_order'],
            static fn (string $table): bool => $table !== 'academies',
        ));

        // The target academy already exists; the archive's academy row only
        // feeds the id map so every academy_id lands on the target.
        $maps['academies'] = [(int) $manifest['academy']['id'] => (int) $target->getKey()];
        $inserted['academies'] = 0;

        $mappable = ['academies', ...$archivedTables];

        foreach ($archivedTables as $table) {
            $plan = $this->planFor($table, $mappable);
            $maps[$table] ??= [];
            $inserted[$table] = 0;
            $deferred = [];

            foreach ($this->rows($zip, (string) $manifest['tables'][$table]['file']) as $row) {
                $oldId = isset($row['id']) ? (int) $row['id'] : null;
                unset($row['id']);

                $row = $this->remapRow($table, $row, $plan, $maps, $target, $oldId, $deferred);

                if ($oldId !== null) {
                    $maps[$table][$oldId] = (int) DB::table($table)->insertGetId($row);
                } else {
                    DB::table($table)->insert($row);
                }

                $inserted[$table]++;
            }

            $this->applyDeferred($table, $deferred, $maps);
        }

        return $inserted;
    }

    /**
     * How each column of $table is rewritten. Computed once per table, applied
     * per row.
     *
     * @param  array<int, string>  $archivedTables
     * @return array{fk: array<string, string>, poly: array<string, string>, self: array<int, string>}
     */
    private function planFor(string $table, array $archivedTables): array
    {
        $columns = Schema::getColumnListing($table);
        $fk = [];
        $poly = [];
        $self = [];

        foreach (Schema::getForeignKeys($table) as $key) {
            if (count($key['columns']) !== 1) {
                continue;
            }

            $column = (string) $key['columns'][0];
            $foreign = (string) $key['foreign_table'];

            if ($column === 'academy_id') {
                continue;
            }

            if ($foreign === $table) {
                $self[] = $column;
            } elseif (in_array($foreign, $archivedTables, true)) {
                $fk[$column] = $foreign;
            }
        }

        foreach ($columns as $column) {
            if (
                $column === 'id'
                || $column === 'academy_id'
                || isset($fk[$column])
                || in_array($column, $self, true)
                || in_array($column, self::EXTERNAL_ID_COLUMNS, true)
                || ! str_ends_with($column, '_id')
            ) {
                continue;
            }

            $stem = substr($column, 0, -3);

            if (in_array($stem.'_type', $columns, true)) {
                $poly[$column] = $stem.'_type';

                continue;
            }

            if ($column === 'parent_id') {
                $self[] = $column;

                continue;
            }

            $guessed = Str::plural($stem);

            if (in_array($guessed, $archivedTables, true)) {
                $fk[$column] = $guessed;
            }
        }

        return ['fk' => $fk, 'poly' => $poly, 'self' => $self];
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array{fk: array<string, string>, poly: array<string, string>, self: array<int, string>}  $plan
     * @param  array<string, array<int, int>>  $maps
     * @param  array<int, array{0: int|null, 1: string, 2: int}>  $deferred
     * @return array<string, string|int|null>
     */
    private function remapRow(string $table, array $row, array $plan, array $maps, Academy $target, ?int $oldId, array &$deferred): array
    {
        if (array_key_exists('academy_id', $row)) {
            $row['academy_id'] = (int) $target->getKey();
        }

        foreach ($plan['fk'] as $column => $foreign) {
            if (($row[$column] ?? null) !== null && $row[$column] !== '') {
                $row[$column] = $this->mapped($maps, $foreign, (int) $row[$column], $table, $column, $oldId);
            }
        }

        foreach ($plan['poly'] as $column => $typeColumn) {
            $value = $row[$column] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $foreign = $this->morphTable((string) ($row[$typeColumn] ?? ''), array_keys($maps));

            if ($foreign !== null && isset($maps[$foreign])) {
                $row[$column] = $this->mapped($maps, $foreign, (int) $value, $table, $column, $oldId);
            }
        }

        foreach ($plan['self'] as $column) {
            $value = $row[$column] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (isset($maps[$table][(int) $value])) {
                $row[$column] = $maps[$table][(int) $value];
            } else {
                // Forward reference inside the same table (rows come ordered by
                // id, but nothing guarantees a parent has the lower id): insert
                // without it and patch once the whole table is in.
                $deferred[] = [$oldId, $column, (int) $value];
                $row[$column] = null;
            }
        }

        return $row;
    }

    /**
     * @param  array<int, array{0: int|null, 1: string, 2: int}>  $deferred
     * @param  array<string, array<int, int>>  $maps
     */
    private function applyDeferred(string $table, array $deferred, array $maps): void
    {
        foreach ($deferred as [$oldId, $column, $oldRef]) {
            if ($oldId === null || ! isset($maps[$table][$oldId])) {
                throw new RuntimeException("Cannot patch [{$table}.{$column}]: the referencing row has no id.");
            }

            $newRef = $maps[$table][$oldRef]
                ?? throw new RuntimeException(
                    "[{$table}] row [{$oldId}] references [{$table}.{$column}] = [{$oldRef}], which is not in the archive."
                );

            DB::table($table)->where('id', $maps[$table][$oldId])->update([$column => $newRef]);
        }
    }

    /**
     * @param  array<string, array<int, int>>  $maps
     */
    private function mapped(array $maps, string $foreign, int $old, string $table, string $column, ?int $oldId): int
    {
        return $maps[$foreign][$old]
            ?? throw new RuntimeException(sprintf(
                '[%s] row [%s] references [%s] id [%d] via [%s], but the archive holds no such row. '
                .'Refusing to guess: keeping the old id could attach the row to another academy\'s data.',
                $table,
                $oldId === null ? '?' : (string) $oldId,
                $foreign,
                $old,
                $column,
            ));
    }

    /**
     * Resolve a polymorphic type value to a table. Class-string morphs map via
     * the model; plain discriminators like answers.session_type ('practice',
     * 'exam') are tried as `{type}_sessions` before the naive plural, because
     * 'exam' would otherwise collide with the exams table.
     *
     * @param  array<int, string>  $knownTables
     */
    private function morphTable(string $type, array $knownTables): ?string
    {
        if ($type === '') {
            return null;
        }

        if (class_exists($type) && is_subclass_of($type, Model::class)) {
            return (new $type)->getTable();
        }

        foreach ([$type.'_sessions', Str::plural($type)] as $candidate) {
            if (in_array($candidate, $knownTables, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Streams one CSV out of the archive as associative rows, undoing exactly
     * what AcademyArchiver's CsvWriter did: BOM, CRLF, and \N for SQL NULL.
     *
     * @return Generator<int, array<string, string|null>>
     */
    private function rows(ZipArchive $zip, string $file): Generator
    {
        $stream = $zip->getStream($file);

        if ($stream === false) {
            throw new RuntimeException("Unable to read [{$file}] from the archive.");
        }

        try {
            $header = fgetcsv($stream, 0, ',', '"', '\\');

            if (! is_array($header) || $header === [null]) {
                return;
            }

            $header[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

            while (($record = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
                if ($record === [null] || $record === []) {
                    continue;
                }

                if (count($record) !== count($header)) {
                    throw new RuntimeException(sprintf(
                        '[%s] is corrupt: a row has %d column(s) where the header promises %d.',
                        $file,
                        count($record),
                        count($header),
                    ));
                }

                yield array_map(
                    static fn (?string $value): ?string => $value === self::NULL_MARKER ? null : $value,
                    array_combine($header, $record),
                );
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, int>  $inserted
     * @param  array<string, int>  $purged
     * @return array<string, mixed>
     */
    private function summary(Academy $target, array $manifest, array $inserted, array $purged, bool $dryRun): array
    {
        $tables = [];

        foreach ($manifest['restore_order'] as $table) {
            $tables[$table] = [
                'archived' => (int) $manifest['tables'][$table]['rows'],
                'inserted' => $inserted[$table] ?? 0,
            ];
        }

        return [
            'dry_run' => $dryRun,
            'archive' => [
                'schema_version' => (string) $manifest['schema_version'],
                'generated_at' => (string) ($manifest['generated_at'] ?? ''),
                'academy' => $manifest['academy'],
            ],
            'target' => [
                'id' => (int) $target->getKey(),
                'slug' => (string) $target->slug,
            ],
            'tables' => $tables,
            'purged' => $purged,
            'total_archived' => array_sum(array_column($tables, 'archived')),
            'total_inserted' => array_sum(array_column($tables, 'inserted')),
        ];
    }
}
