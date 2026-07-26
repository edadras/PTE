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
 * Fill in the Blanks — listening (typed), reading (dropdown) and reading-writing
 * (drag & drop).
 *
 * One point per correctly filled blank, no deduction: score = correct / blanks.
 * PTE treats each blank as an independent item, so a student who gets three of
 * five right has genuinely earned three fifths — collapsing that to all-or-
 * nothing would throw away most of the signal the item produces.
 *
 * A blank may declare several acceptable fillings (["colour","color"]); any of
 * them scores.
 */
final class FillInBlanksScorer implements Scorer
{
    use ReadsAnswerPayload;

    public function __construct(private readonly TextNormalizer $text = new TextNormalizer) {}

    public function supports(QuestionType $type): bool
    {
        return in_array($type, [
            QuestionType::FillInBlanksListening,
            QuestionType::FillInBlanksReading,
            QuestionType::FillInBlanksReadingWriting,
        ], true);
    }

    public function score(Answer $answer): ScoreResult
    {
        $context = $answer->context();
        $max = $answer->effectiveMaxScore();

        $expected = $context->correctList(['blanks', 'answers', 'values']);

        if ($expected === []) {
            return ScoreResult::make(0.0, $max, ['reason' => 'missing_correct_answer'], [
                'label' => 'assessment.feedback.needs_review',
            ], 0.0);
        }

        $given = $this->submittedList($answer, ['blanks', 'answers', 'values', 'options']);
        $total = count($expected);

        if ($given === []) {
            return ScoreResult::zero($max, [
                'correct' => 0,
                'total' => $total,
                'blanks' => [],
            ], ['label' => 'assessment.feedback.no_answer']);
        }

        $blanks = [];
        $correct = 0;

        foreach (array_values($expected) as $index => $accepted) {
            $submitted = $this->text->key($given[$index] ?? ($given[(string) $index] ?? null));
            $isCorrect = $submitted !== '' && in_array($submitted, $this->accepted($accepted), true);

            $correct += $isCorrect ? 1 : 0;

            $blanks[] = [
                'index' => $index,
                'given' => $submitted,
                'correct' => $isCorrect,
            ];
        }

        $ratio = $correct / $total;

        return ScoreResult::fromRatio($ratio, $max, [
            'correct' => $correct,
            'total' => $total,
            'blanks' => $blanks,
        ], [
            'label' => match (true) {
                $ratio >= 1.0 => 'assessment.feedback.perfect',
                $ratio >= 0.5 => 'assessment.feedback.partial',
                default => 'assessment.feedback.weak',
            },
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function accepted(mixed $expected): array
    {
        if (is_array($expected)) {
            // {"accepted": [...]} or a bare list of alternatives.
            $candidates = $expected['accepted'] ?? $expected['options'] ?? $expected;

            return array_values(array_filter(array_map(
                fn (mixed $value): string => $this->text->key($value),
                is_array($candidates) ? $candidates : [$candidates]
            ), static fn (string $value): bool => $value !== ''));
        }

        $key = $this->text->key($expected);

        return $key === '' ? [] : [$key];
    }
}
