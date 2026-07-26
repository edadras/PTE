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
 * Highlight Incorrect Words.
 *
 * Negative marking, floored at zero: score = (hits − false positives) / expected.
 *
 * The deduction is the whole point of the item. Without it the optimal strategy
 * is to select every word in the passage and collect full marks, which measures
 * nothing. Flooring at zero keeps one reckless answer from dragging a session
 * total negative — PTE does the same.
 */
final class HighlightIncorrectWordsScorer implements Scorer
{
    use ReadsAnswerPayload;

    public function __construct(private readonly TextNormalizer $text = new TextNormalizer) {}

    public function supports(QuestionType $type): bool
    {
        return $type === QuestionType::HighlightIncorrectWords;
    }

    public function score(Answer $answer): ScoreResult
    {
        $context = $answer->context();
        $max = $answer->effectiveMaxScore();

        $expected = $this->keys($context->correctList([
            'incorrect_words', 'words', 'keys', 'positions', 'answers',
        ]));

        if ($expected === []) {
            return ScoreResult::make(0.0, $max, ['reason' => 'missing_correct_answer'], [
                'label' => 'assessment.feedback.needs_review',
            ], 0.0);
        }

        $given = $this->keys($this->submittedList($answer, ['options', 'selected', 'words', 'keys', 'positions']));

        if ($given === []) {
            return ScoreResult::zero($max, [
                'expected' => $expected,
                'hits' => 0,
                'false_positives' => 0,
                'total' => count($expected),
            ], ['label' => 'assessment.feedback.no_answer']);
        }

        $hits = array_values(array_intersect($given, $expected));
        $falsePositives = array_values(array_diff($given, $expected));
        $missed = array_values(array_diff($expected, $given));

        $net = max(0, count($hits) - count($falsePositives));
        $ratio = $net / count($expected);

        return ScoreResult::fromRatio($ratio, $max, [
            'expected' => $expected,
            'given' => $given,
            'hits' => count($hits),
            'false_positives' => count($falsePositives),
            'missed' => $missed,
            'total' => count($expected),
            'net' => $net,
        ], [
            'label' => $this->label($ratio, count($falsePositives)),
            'over_selected' => count($falsePositives) > 0,
        ]);
    }

    /**
     * Selections may be word strings or word indexes; both are compared as
     * normalised strings so the two clients can disagree freely.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<int, string>
     */
    private function keys(array $values): array
    {
        $keys = [];

        foreach ($values as $value) {
            // Rich payloads such as {"index": 4, "word": "rapid"} favour the index.
            if (is_array($value)) {
                $value = $value['index'] ?? $value['key'] ?? $value['word'] ?? $value['text'] ?? null;
            }

            $key = $this->text->key($value);

            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function label(float $ratio, int $falsePositives): string
    {
        if ($falsePositives > 0 && $ratio < 0.5) {
            return 'assessment.feedback.over_selected';
        }

        return match (true) {
            $ratio >= 1.0 => 'assessment.feedback.perfect',
            $ratio >= 0.5 => 'assessment.feedback.partial',
            default => 'assessment.feedback.weak',
        };
    }
}
