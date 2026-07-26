<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Enums\SelectionMode;
use App\Domain\Assessment\Models\ExamQuestion;
use App\Domain\Assessment\Models\ExamSection;
use Illuminate\Support\Facades\DB;

/**
 * Projects a chosen list of question ids onto `exam_questions` for one section.
 *
 * Manual and pool sections keep explicit rows (SelectionMode::usesExplicitQuestions),
 * and PublishExam refuses an empty section by counting them — so the projection
 * has to be exact: rows that fell out of the list are pruned, survivors keep
 * their identity but take the new sort_order, and the per-question score is
 * re-derived from the section total.
 *
 * Update-in-place rather than delete-and-reinsert on purpose: a row that
 * survives an edit keeps its id, so nothing referencing it goes stale.
 *
 * @see docs/05-modules-exams-practice.md §5 · docs/07-database-schema.md §7
 */
final class UpsertExamSectionQuestions
{
    /**
     * @param  array<int, int>  $questionIds  In presentation order; duplicates collapse to first occurrence.
     */
    public function handle(ExamSection $section, array $questionIds): void
    {
        // A random section holds no explicit rows; stale ones from a mode
        // switch would double-count in PublishExam.
        if (! $section->selection_mode->usesExplicitQuestions()) {
            ExamQuestion::query()->where('exam_section_id', $section->getKey())->delete();

            return;
        }

        /** @var array<int, int> $ids */
        $ids = array_values(array_unique(array_map(intval(...), $questionIds)));

        DB::transaction(function () use ($section, $ids): void {
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
        });
    }

    /**
     * The section total divided over what a student actually answers — the pool
     * hands out `take` questions, not the whole candidate list.
     */
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
