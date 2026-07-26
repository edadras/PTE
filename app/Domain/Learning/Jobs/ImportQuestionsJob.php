<?php

declare(strict_types=1);

namespace App\Domain\Learning\Jobs;

use App\Domain\Learning\Actions\ImportQuestions;
use App\Domain\Learning\Data\ImportReport;
use App\Domain\Learning\Exceptions\QuestionImportException;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Shared\Support\TenantKey;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs an import off the request cycle.
 *
 * Progress and the final report are parked in the tenant cache under the batch
 * id so the panel can poll a progress bar and then show the per-row errors —
 * docs/05 §3 asks for both.
 */
final class ImportQuestionsJob extends TenantAwareJob
{
    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        int $academyId,
        public readonly string $path,
        public readonly int $bankId,
        public readonly string $batchId,
        public readonly array $options = [],
    ) {
        parent::__construct($academyId);
    }

    public function handle(ImportQuestions $import): void
    {
        $this->publish(['status' => 'running', 'imported' => 0, 'failed' => 0]);

        $bank = QuestionBank::query()->findOrFail($this->bankId);

        try {
            $report = $import->handle($this->path, $bank, [
                ...$this->options,
                'batch_id' => $this->batchId,
            ]);

            $this->publish(['status' => 'completed', ...$report->toArray()]);
        } catch (QuestionImportException $exception) {
            $this->publish(['status' => 'failed', ...$exception->report->toArray()]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof QuestionImportException) {
            return;
        }

        $this->publish([
            'status' => 'failed',
            'errors' => [['line' => 0, 'messages' => [$exception?->getMessage() ?? 'Unknown error']]],
        ]);
    }

    public function progressKey(): string
    {
        return self::progressKeyFor($this->academyId, $this->batchId);
    }

    /** Resolved from the stored academy id — `failed()` may run with no tenant set. */
    public static function progressKeyFor(int $academyId, string $batchId): string
    {
        return TenantKey::for($academyId, 'import', $batchId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publish(array $payload): void
    {
        Cache::put($this->progressKey(), $payload, now()->addHours(6));
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [...parent::tags(), 'import:'.$this->batchId];
    }

    public static function reportFor(int $academyId, string $batchId): ?ImportReport
    {
        $payload = Cache::get(self::progressKeyFor($academyId, $batchId));

        if (! is_array($payload) || ! isset($payload['batch_id'])) {
            return null;
        }

        return new ImportReport(
            batchId: (string) $payload['batch_id'],
            totalRows: (int) ($payload['total_rows'] ?? 0),
            imported: (int) ($payload['imported'] ?? 0),
            errors: [],
            rolledBack: (bool) ($payload['rolled_back'] ?? false),
            questionIds: array_map('intval', $payload['question_ids'] ?? []),
        );
    }
}
