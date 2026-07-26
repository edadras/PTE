<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Scoring\Scorers;

use App\Domain\Assessment\Concerns\ReadsAnswerPayload;
use App\Domain\Assessment\Contracts\Scorer;
use App\Domain\Assessment\Data\ScoreResult;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Scoring\Support\TextNormalizer;
use App\Domain\Learning\Enums\QuestionType;

/**
 * Re-order Paragraphs.
 *
 * The real PTE rule, and it is not the obvious one: credit is given per
 * correctly ordered *adjacent pair*, not per paragraph in its absolute position.
 * Maximum raw score is therefore n − 1.
 *
 * The reason is that adjacency is what the item actually tests — recognising
 * that paragraph C leads into A. Positional marking would punish a student who
 * got the whole chain right but started one step off, scoring them zero for an
 * answer that demonstrates almost complete understanding.
 */
final class ReorderParagraphsScorer implements Scorer
{
    use ReadsAnswerPayload;

    public function __construct(private readonly TextNormalizer $text = new TextNormalizer) {}

    public function supports(QuestionType $type): bool
    {
        return $type === QuestionType::ReorderParagraphs;
    }

    public function score(Answer $answer): ScoreResult
    {
        $context = $answer->context();
        $max = $answer->effectiveMaxScore();

        $expected = $this->keys($context->correctList(['correct_order', 'order', 'sequence']));
        $pairsAvailable = count($expected) - 1;

        if ($pairsAvailable < 1) {
            return ScoreResult::make(0.0, $max, ['reason' => 'missing_correct_answer'], [
                'label' => 'assessment.feedback.needs_review',
            ], 0.0);
        }

        $given = $this->keys($this->submittedList($answer, ['order', 'sequence', 'keys', 'paragraphs']));

        if ($given === []) {
            return ScoreResult::zero($max, [
                'correct_pairs' => 0,
                'total_pairs' => $pairsAvailable,
                'expected_order' => $expected,
            ], ['label' => 'assessment.feedback.no_answer']);
        }

        $correctPairs = [];

        for ($i = 0; $i < count($expected) - 1; $i++) {
            $correctPairs[$expected[$i].'>'.$expected[$i + 1]] = true;
        }

        $matched = 0;
        $pairs = [];

        for ($i = 0; $i < count($given) - 1; $i++) {
            $pair = $given[$i].'>'.$given[$i + 1];
            $isCorrect = isset($correctPairs[$pair]);
            $matched += $isCorrect ? 1 : 0;
            $pairs[] = ['pair' => $pair, 'correct' => $isCorrect];
        }

        $ratio = $matched / $pairsAvailable;

        return ScoreResult::fromRatio($ratio, $max, [
            'correct_pairs' => $matched,
            'total_pairs' => $pairsAvailable,
            'pairs' => $pairs,
            'expected_order' => $expected,
            'given_order' => $given,
        ], [
            'label' => match (true) {
                $ratio >= 1.0 => 'assessment.feedback.perfect',
                $ratio >= 0.5 => 'assessment.feedback.partial',
                default => 'assessment.feedback.weak',
            },
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<int, string>
     */
    private function keys(array $values): array
    {
        $keys = [];

        foreach ($values as $value) {
            if (is_array($value)) {
                $value = $value['key'] ?? $value['id'] ?? $value['index'] ?? null;
            }

            $key = $this->text->key($value);

            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
