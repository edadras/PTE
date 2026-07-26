<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Data\ImportReport;
use App\Domain\Learning\Data\ImportRowError;
use App\Domain\Learning\Data\QuestionData;
use App\Domain\Learning\Enums\Difficulty;
use App\Domain\Learning\Enums\QuestionStatus;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Exceptions\InvalidQuestionContentException;
use App\Domain\Learning\Exceptions\QuestionImportException;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Learning\Services\QuestionContentValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Bulk import from a delimited file.
 *
 * Deliberately implemented on top of fgetcsv rather than a spreadsheet library:
 * the platform has no such dependency, and a streamed read keeps a 10k-row file
 * at constant memory. Real .xlsx is rejected with a clear message instead of
 * being silently mangled.
 *
 * Every row is validated through the same QuestionContentValidator the panel
 * uses, and every failure is reported with its line number — a teacher fixing a
 * spreadsheet needs to know which line, not that "the import failed".
 *
 * @see docs/05-modules-exams-practice.md §3
 */
final class ImportQuestions
{
    /** Columns understood on top of the per-type content fields. */
    private const META_COLUMNS = [
        'type', 'title', 'difficulty', 'tags', 'status', 'content',
        'bank', 'bank_id', 'correct_options', 'correct_option', 'audio_file', 'image_file',
    ];

    /** Content fields that hold JSON when present. */
    private const JSON_FIELDS = ['paragraphs', 'correct_order', 'blanks', 'accepted_answers', 'key_points', 'errors', 'options'];

    private const INT_FIELDS = [
        'prep_seconds', 'record_seconds', 'play_count', 'min_words', 'max_words',
        'duration_minutes', 'duration_seconds', 'word_count',
    ];

    public function __construct(
        private readonly QuestionContentValidator $validator,
        private readonly CreateQuestion $createQuestion,
    ) {}

    /**
     * @param  array{
     *     all_or_nothing?: bool,
     *     status?: QuestionStatus,
     *     created_by?: int|null,
     *     delimiter?: string,
     *     batch_id?: string
     * }  $options
     *
     * @throws QuestionImportException
     */
    public function handle(string $path, QuestionBank $bank, array $options = []): ImportReport
    {
        $allOrNothing = $options['all_or_nothing'] ?? true;
        $status = $options['status'] ?? QuestionStatus::Draft;
        $createdBy = $options['created_by'] ?? null;
        $batchId = $options['batch_id'] ?? (string) Str::uuid();

        $rows = $this->readRows($path, $options['delimiter'] ?? null);

        /** @var array<int, ImportRowError> $errors */
        $errors = [];
        $prepared = [];

        foreach ($rows as $line => $row) {
            try {
                $prepared[] = [$line, $this->toQuestionData($row, $bank, $createdBy, $batchId)];
            } catch (InvalidQuestionContentException $exception) {
                $errors[] = new ImportRowError($line, $exception->flatErrors(), null, $row);
            } catch (Throwable $exception) {
                $errors[] = new ImportRowError($line, [$exception->getMessage()], null, $row);
            }
        }

        if ($errors !== [] && $allOrNothing) {
            $report = new ImportReport($batchId, count($rows), 0, $errors, true);

            throw QuestionImportException::rolledBack($report);
        }

        $created = [];

        try {
            DB::transaction(function () use ($prepared, $status, &$created, &$errors, $allOrNothing): void {
                foreach ($prepared as [$line, $data]) {
                    try {
                        $created[] = (int) $this->createQuestion->handle($data, $status)->getKey();
                    } catch (Throwable $exception) {
                        if ($allOrNothing) {
                            throw $exception;
                        }

                        $errors[] = new ImportRowError($line, [$exception->getMessage()]);
                    }
                }
            });
        } catch (Throwable $exception) {
            $report = new ImportReport(
                $batchId,
                count($rows),
                0,
                [...$errors, new ImportRowError(0, [$exception->getMessage()])],
                true
            );

            throw QuestionImportException::rolledBack($report);
        }

        $bank->refreshQuestionCount();

        return new ImportReport(
            batchId: $batchId,
            totalRows: count($rows),
            imported: count($created),
            errors: $errors,
            rolledBack: false,
            questionIds: $created,
        );
    }

    /**
     * Undo a completed import. Soft deletes, so nothing a student already
     * answered loses its referent.
     *
     * @return int Number of questions withdrawn.
     */
    public function rollback(string $batchId): int
    {
        $questions = Question::query()->where('import_batch_id', $batchId)->get();

        foreach ($questions as $question) {
            $question->delete();
        }

        return $questions->count();
    }

    /**
     * Validate a file without writing anything — the "preview" step in the panel.
     *
     * @param  array<string, mixed>  $options
     */
    public function dryRun(string $path, QuestionBank $bank, array $options = []): ImportReport
    {
        $rows = $this->readRows($path, $options['delimiter'] ?? null);
        $errors = [];
        $ok = 0;

        foreach ($rows as $line => $row) {
            try {
                $this->toQuestionData($row, $bank, null, '');
                $ok++;
            } catch (Throwable $exception) {
                $errors[] = new ImportRowError($line, [$exception->getMessage()], null, $row);
            }
        }

        return new ImportReport('', count($rows), $ok, $errors, true);
    }

    /**
     * @return array<int, array<string, string>> Keyed by 1-based file line number.
     */
    private function readRows(string $path, ?string $delimiter): array
    {
        if (str_ends_with(strtolower($path), '.xlsx') || str_ends_with(strtolower($path), '.xls')) {
            throw new QuestionImportException(
                new ImportReport('', 0, 0, [], true),
                'Binary Excel files are not supported. Save the sheet as CSV (UTF-8) and import that.'
            );
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw QuestionImportException::unreadableFile($path);
        }

        $delimiter ??= str_ends_with(strtolower($path), '.tsv') ? "\t" : ',';
        $rows = [];
        $header = null;
        $line = 0;

        try {
            while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;

                if ($record === [null] || $record === []) {
                    continue;
                }

                if ($header === null) {
                    // Strip a UTF-8 BOM so the first column name still matches.
                    $record[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $record[0]);
                    $header = array_map(
                        static fn (mixed $column): string => Str::snake(trim(mb_strtolower((string) $column))),
                        $record
                    );

                    continue;
                }

                if (count(array_filter($record, static fn (mixed $v): bool => trim((string) $v) !== '')) === 0) {
                    continue;
                }

                $padded = array_pad(array_slice($record, 0, count($header)), count($header), '');
                $rows[$line] = array_combine($header, array_map(static fn (mixed $v): string => trim((string) $v), $padded));
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $row
     *
     * @throws InvalidQuestionContentException
     */
    private function toQuestionData(array $row, QuestionBank $bank, ?int $createdBy, string $batchId): QuestionData
    {
        $rawType = strtoupper(str_replace('-', '_', trim($row['type'] ?? '')));
        $type = QuestionType::tryFrom($rawType);

        if (! $type instanceof QuestionType) {
            throw new InvalidArgumentException(sprintf(
                'Unknown question type [%s]. Expected one of: %s.',
                $row['type'] ?? '',
                implode(', ', array_map(static fn (QuestionType $t): string => $t->value, QuestionType::cases()))
            ));
        }

        $content = $this->contentFrom($row);
        $options = $this->optionsFrom($row);

        $errors = $this->validator->errorsFor($type, $content);

        if ($this->validator->requiresOptions($type)) {
            $errors = [...$errors, ...$this->validator->errorsForOptions($type, $options, $content)];
        }

        if ($errors !== []) {
            throw InvalidQuestionContentException::for($type, $errors);
        }

        return new QuestionData(
            type: $type,
            bankId: (int) $bank->getKey(),
            content: $content,
            title: ($row['title'] ?? '') !== '' ? $row['title'] : null,
            difficulty: Difficulty::tryFrom(strtolower($row['difficulty'] ?? '')) ?? Difficulty::Medium,
            tags: $this->tagsFrom($row['tags'] ?? ''),
            options: $options,
            createdBy: $createdBy,
            importBatchId: $batchId !== '' ? $batchId : null,
        );
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function contentFrom(array $row): array
    {
        // A `content` column wins outright — that is the round-trip format the
        // panel exports.
        if (($row['content'] ?? '') !== '') {
            $decoded = json_decode($row['content'], true);

            if (! is_array($decoded)) {
                throw new InvalidArgumentException('Column [content] is not valid JSON.');
            }

            return $decoded;
        }

        $content = [];

        foreach ($row as $column => $value) {
            if ($value === '' || in_array($column, self::META_COLUMNS, true) || str_starts_with($column, 'option_')) {
                continue;
            }

            if (in_array($column, self::JSON_FIELDS, true)) {
                $decoded = json_decode($value, true);

                if (! is_array($decoded)) {
                    throw new InvalidArgumentException(sprintf('Column [%s] must contain a JSON array.', $column));
                }

                $content[$column] = $decoded;

                continue;
            }

            if (in_array($column, self::INT_FIELDS, true)) {
                if (! is_numeric($value)) {
                    throw new InvalidArgumentException(sprintf('Column [%s] must be a number.', $column));
                }

                $content[$column] = (int) $value;

                continue;
            }

            if ($column === 'multiple' || $column === 'case_sensitive' || $column === 'negative_marking') {
                $content[$column] = filter_var($value, FILTER_VALIDATE_BOOLEAN);

                continue;
            }

            $content[$column] = $value;
        }

        return $content;
    }

    /**
     * Accepts either an `options` JSON column or spreadsheet-friendly
     * `option_a … option_h` columns plus a `correct_options` list.
     *
     * @param  array<string, string>  $row
     * @return array<int, array<string, mixed>>
     */
    private function optionsFrom(array $row): array
    {
        if (($row['options'] ?? '') !== '') {
            $decoded = json_decode($row['options'], true);

            if (! is_array($decoded)) {
                throw new InvalidArgumentException('Column [options] is not valid JSON.');
            }

            return array_values($decoded);
        }

        $correct = array_map(
            static fn (string $key): string => strtoupper(trim($key)),
            array_filter(explode(',', $row['correct_options'] ?? $row['correct_option'] ?? ''))
        );

        $options = [];
        $index = 0;

        foreach (range('a', 'h') as $letter) {
            $value = $row['option_'.$letter] ?? '';

            if ($value === '') {
                continue;
            }

            $key = strtoupper($letter);

            $options[] = [
                'key' => $key,
                'text' => $value,
                'is_correct' => in_array($key, $correct, true),
                'sort_order' => $index++,
            ];
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function tagsFrom(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return array_values(array_map('strval', $decoded));
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
