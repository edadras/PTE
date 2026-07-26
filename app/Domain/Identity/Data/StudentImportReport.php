<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use App\Domain\Learning\Data\ImportRowError;

/**
 * Result of a student bulk import: what landed, what did not, and under which
 * batch id so the whole thing can be undone later.
 *
 * Reuses Learning's ImportRowError on purpose — one shape for "line N of your
 * file is wrong because…" across every import surface the panel offers.
 */
final readonly class StudentImportReport
{
    /**
     * @param  array<int, ImportRowError>  $errors
     * @param  array<int, int>  $studentIds
     */
    public function __construct(
        public string $batchId,
        public int $totalRows,
        public int $imported,
        public array $errors = [],
        public bool $rolledBack = false,
        public array $studentIds = [],
    ) {}

    public function failedCount(): int
    {
        return count($this->errors);
    }

    public function isClean(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'total_rows' => $this->totalRows,
            'imported' => $this->imported,
            'failed' => $this->failedCount(),
            'rolled_back' => $this->rolledBack,
            'student_ids' => $this->studentIds,
            'errors' => array_map(static fn (ImportRowError $error): array => $error->toArray(), $this->errors),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function errorLines(): array
    {
        return array_map(static fn (ImportRowError $error): string => $error->toString(), $this->errors);
    }
}
