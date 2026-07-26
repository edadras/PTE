<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data;

/**
 * Result of a bulk import: what landed, what did not, and under which batch id
 * so the whole thing can be undone later.
 */
final readonly class ImportReport
{
    /**
     * @param  array<int, ImportRowError>  $errors
     * @param  array<int, int>  $questionIds
     */
    public function __construct(
        public string $batchId,
        public int $totalRows,
        public int $imported,
        public array $errors = [],
        public bool $rolledBack = false,
        public array $questionIds = [],
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
            'question_ids' => $this->questionIds,
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

    public function asRolledBack(): self
    {
        return new self(
            batchId: $this->batchId,
            totalRows: $this->totalRows,
            imported: 0,
            errors: $this->errors,
            rolledBack: true,
            questionIds: [],
        );
    }
}
