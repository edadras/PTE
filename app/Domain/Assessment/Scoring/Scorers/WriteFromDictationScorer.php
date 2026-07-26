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
 * Write From Dictation.
 *
 * PTE marks WFD word by word: every word of the played sentence that the student
 * reproduces correctly, in order, earns a point, and the raw score is
 * correct / total. Nothing else matters — not capitalisation, not the full stop,
 * and not extra words, which cost nothing on their own but push correct words
 * out of sequence.
 *
 * We measure "correct and in order" with a longest common subsequence rather
 * than a set intersection, because a student who writes the right vocabulary in
 * the wrong order has not taken the dictation down correctly and should not get
 * full marks.
 */
final class WriteFromDictationScorer implements Scorer
{
    use ReadsAnswerPayload;

    public function __construct(private readonly TextNormalizer $text = new TextNormalizer) {}

    public function supports(QuestionType $type): bool
    {
        return $type === QuestionType::WriteFromDictation;
    }

    public function score(Answer $answer): ScoreResult
    {
        $context = $answer->context();
        $max = $answer->effectiveMaxScore();

        $expectedText = $context->content('transcript')
            ?? $context->content('text')
            ?? $context->correct('transcript');

        $expected = $this->text->words(is_string($expectedText) ? $expectedText : null);

        if ($expected === []) {
            // No transcript on the question: grading it zero would be unfair, so
            // it goes to a human instead (ScoringDispatcher reads confidence 0).
            return ScoreResult::make(0.0, $max, ['reason' => 'missing_transcript'], [
                'label' => 'assessment.feedback.needs_review',
            ], 0.0);
        }

        $given = $this->text->words($this->submittedText($answer));

        if ($given === []) {
            return ScoreResult::zero($max, [
                'correct_words' => 0,
                'total_words' => count($expected),
                'missing' => $expected,
            ], ['label' => 'assessment.feedback.no_answer']);
        }

        $correct = $this->text->longestCommonSubsequence($expected, $given);
        $ratio = $correct / count($expected);

        $missing = $this->text->missingWords($expected, $given);
        $extra = $this->text->extraWords($expected, $given);

        return ScoreResult::fromRatio($ratio, $max, [
            'correct_words' => $correct,
            'total_words' => count($expected),
            'submitted_words' => count($given),
            'missing' => array_slice($missing, 0, 20),
            'extra' => array_slice($extra, 0, 20),
        ], [
            'label' => $this->label($ratio),
            'missing_words' => array_slice($missing, 0, 10),
        ]);
    }

    private function label(float $ratio): string
    {
        return match (true) {
            $ratio >= 1.0 => 'assessment.feedback.perfect',
            $ratio >= 0.8 => 'assessment.feedback.strong',
            $ratio >= 0.5 => 'assessment.feedback.partial',
            default => 'assessment.feedback.weak',
        };
    }
}
