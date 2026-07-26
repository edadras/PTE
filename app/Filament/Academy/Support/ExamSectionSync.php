<?php

declare(strict_types=1);

namespace App\Filament\Academy\Support;

use App\Domain\Assessment\Actions\UpsertExamSectionQuestions;
use App\Domain\Assessment\Models\Exam;
use Illuminate\Support\Facades\DB;

/**
 * Walks an exam's sections and hands each one's chosen question ids to the
 * domain action.
 *
 * The projection itself lives in Assessment — this is only the builder-shaped
 * entry point that reads `selection_config.question_ids` off the saved form
 * state. One transaction around the whole exam, so a half-projected exam can
 * never be what PublishExam counts.
 */
final class ExamSectionSync
{
    public function __construct(private readonly UpsertExamSectionQuestions $upsert) {}

    public function handle(Exam $exam): void
    {
        DB::transaction(function () use ($exam): void {
            foreach ($exam->sections()->get() as $section) {
                /** @var array<int, int> $ids */
                $ids = (array) $section->config('question_ids', []);

                $this->upsert->handle($section, $ids);
            }
        });
    }
}
