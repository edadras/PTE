<?php

declare(strict_types=1);

namespace App\Filament\Academy\Support;

use App\Domain\Assessment\Enums\SelectionMode;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamQuestion;
use App\Domain\Assessment\Models\ExamSection;
use Illuminate\Support\Facades\DB;

/**
 * Projects the builder's `selection_config.question_ids` onto `exam_questions`.
 *
 * Manual and pool sections keep explicit rows (SelectionMode::usesExplicitQuestions),
 * and PublishExam counts them, so the two have to be kept in step. There is no
 * Assessment action for this yet — see the hand-over notes.
 */
final class ExamSectionSync
{
    public function handle(Exam $exam): void
    {
        DB::transaction(function () use ($exam): void {
            foreach ($exam->sections()->get() as $section) {
                $this->syncSection($section);
            }
        });
    }

    private function syncSection(ExamSection $section): void
    {
        if (! $section->selection_mode->usesExplicitQuestions()) {
            ExamQuestion::query()->where('exam_section_id', $section->getKey())->delete();

            return;
        }

        /** @var array<int, int> $ids */
        $ids = array_values(array_unique(array_map(
            intval(...),
            (array) $section->config('question_ids', []),
        )));

        ExamQuestion::query()
            ->where('exam_section_id', $section->getKey())
            ->whereNotIn('question_id', $ids === [] ? [0] : $ids)
            ->delete();

        $score = $this->perQuestionScore($section, count($ids));

        foreach ($ids as $order => $questionId) {
            ExamQuestion::query()->updateOrCreate(
                [
                    'exam_section_id' => $section->getKey(),
                    'question_id' => $questionId,
                ],
                [
                    'sort_order' => $order,
                    'score' => $score,
                ],
            );
        }
    }

    private function perQuestionScore(ExamSection $section, int $count): ?float
    {
        if ($count < 1 || $section->score <= 0.0) {
            return null;
        }

        $take = $section->selection_mode === SelectionMode::Pool
            ? max(1, (int) $section->config('take', $count))
            : $count;

        return round($section->score / $take, 4);
    }
}
