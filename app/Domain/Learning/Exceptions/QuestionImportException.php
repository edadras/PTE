<?php

declare(strict_types=1);

namespace App\Domain\Learning\Exceptions;

use App\Domain\Learning\Data\ImportReport;
use RuntimeException;

/**
 * Thrown when an all-or-nothing import hits at least one bad row. The report
 * rides along so the caller can show every failure, not just the first.
 */
final class QuestionImportException extends RuntimeException
{
    public function __construct(public readonly ImportReport $report, string $message = '')
    {
        parent::__construct(
            $message !== '' ? $message : sprintf('Import aborted: %d of %d rows failed validation.', $report->failedCount(), $report->totalRows)
        );
    }

    public static function rolledBack(ImportReport $report): self
    {
        return new self($report);
    }

    public static function unreadableFile(string $path): self
    {
        return new self(
            new ImportReport(batchId: '', totalRows: 0, imported: 0, errors: [], rolledBack: true),
            sprintf('Import file [%s] could not be opened.', $path)
        );
    }
}
