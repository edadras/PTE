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
 * Single-answer multiple choice: MCQ-L, MCQ-R and Select Missing Word.
 *
 * All or nothing. PTE awards no partial credit on a single-answer item — there
 * is nothing to be partially right about — and the option key is compared
 * case-insensitively so a client sending "a" instead of "A" is not punished for
 * a serialisation detail.
 */
final class MultipleChoiceScorer implements Scorer
{
    use ReadsAnswerPayload;

    public function __construct(private readonly TextNormalizer $text = new TextNormalizer) {}

    public function supports(QuestionType $type): bool
    {
        return in_array($type, [
            QuestionType::MultipleChoiceListening,
            QuestionType::MultipleChoiceReading,
            QuestionType::SelectMissingWord,
        ], true);
    }

    public function score(Answer $answer): ScoreResult
    {
        $max = $answer->effectiveMaxScore();
        $expected = $this->expectedKey($answer);

        if ($expected === null) {
            return ScoreResult::make(0.0, $max, ['reason' => 'missing_correct_answer'], [
                'label' => 'assessment.feedback.needs_review',
            ], 0.0);
        }

        $given = $this->submittedScalar($answer, ['option', 'answer', 'selected', 'key', 'choice']);

        if ($given === null) {
            return ScoreResult::zero($max, [
                'expected' => $expected,
                'given' => null,
            ], ['label' => 'assessment.feedback.no_answer']);
        }

        $isCorrect = $this->text->key($given) === $expected;

        return ScoreResult::make($isCorrect ? $max : 0.0, $max, [
            'expected' => $expected,
            'given' => $this->text->key($given),
            'correct' => $isCorrect,
        ], [
            'label' => $isCorrect ? 'assessment.feedback.correct' : 'assessment.feedback.incorrect',
        ]);
    }

    private function expectedKey(Answer $answer): ?string
    {
        $context = $answer->context();

        $value = $context->correct('option')
            ?? $context->correct('key')
            ?? $context->correct('answer')
            ?? $context->correct('correct_option');

        if ($value === null) {
            $list = $context->correctList(['options', 'keys', 'answers']);
            $value = $list[0] ?? null;
        }

        if ($value === null || (is_scalar($value) && (string) $value === '')) {
            return null;
        }

        $key = $this->text->key($value);

        return $key === '' ? null : $key;
    }
}
