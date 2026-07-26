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
 * Answer Short Question.
 *
 * The only spoken task that needs no language model: the expected answer is one
 * or two words, so once ASR has produced a transcript the grading is a keyword
 * match. That is why ASQ sits on the deterministic side of QuestionType::
 * requiresAi() despite being a Speaking item — it removes a fifth of all
 * speaking AI calls at no cost in accuracy.
 *
 * Two authoring shapes are supported:
 *   accepted_answers → any one of them scores full marks (the PTE rule: ASQ is
 *                      binary, you either named the thing or you did not);
 *   keywords         → proportional credit, for academies that write multi-part
 *                      short questions ("name two of the three causes").
 */
final class AnswerShortQuestionScorer implements Scorer
{
    use ReadsAnswerPayload;

    public function __construct(private readonly TextNormalizer $text = new TextNormalizer) {}

    public function supports(QuestionType $type): bool
    {
        return $type === QuestionType::AnswerShortQuestion;
    }

    public function score(Answer $answer): ScoreResult
    {
        $context = $answer->context();
        $max = $answer->effectiveMaxScore();

        $keywords = $this->phrases($context->correct('keywords') ?? $context->content('keywords'));
        $accepted = $this->phrases(
            $context->correct('accepted_answers')
            ?? $context->correct('accepted')
            ?? $context->content('accepted_answers')
        );

        if ($accepted === []) {
            $accepted = $this->phrases($context->correctList(['answers', 'accepted_answers']));
        }

        if ($keywords === [] && $accepted === []) {
            return ScoreResult::make(0.0, $max, ['reason' => 'missing_correct_answer'], [
                'label' => 'assessment.feedback.needs_review',
            ], 0.0);
        }

        // ASR output, or typed text when the academy practises ASQ in writing.
        $transcript = $this->submittedText($answer);

        if ($transcript === null || $this->text->normalize($transcript) === '') {
            return ScoreResult::zero($max, [
                'accepted' => $accepted,
                'keywords' => $keywords,
                'matched' => [],
            ], ['label' => 'assessment.feedback.no_speech']);
        }

        if ($keywords !== []) {
            $matched = array_values(array_filter(
                $keywords,
                fn (string $keyword): bool => $this->text->containsPhrase($transcript, $keyword)
            ));

            $ratio = count($matched) / count($keywords);

            return ScoreResult::fromRatio($ratio, $max, [
                'keywords' => $keywords,
                'matched' => $matched,
                'missed' => array_values(array_diff($keywords, $matched)),
                'transcript' => $this->text->normalize($transcript),
            ], [
                'label' => match (true) {
                    $ratio >= 1.0 => 'assessment.feedback.correct',
                    $ratio > 0.0 => 'assessment.feedback.partial',
                    default => 'assessment.feedback.incorrect',
                },
            ]);
        }

        $hit = null;

        foreach ($accepted as $candidate) {
            if ($this->text->containsPhrase($transcript, $candidate)) {
                $hit = $candidate;
                break;
            }
        }

        return ScoreResult::make($hit !== null ? $max : 0.0, $max, [
            'accepted' => $accepted,
            'matched' => $hit === null ? [] : [$hit],
            'transcript' => $this->text->normalize($transcript),
        ], [
            'label' => $hit !== null ? 'assessment.feedback.correct' : 'assessment.feedback.incorrect',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function phrases(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $values = is_array($value) ? $value : [$value];
        $phrases = [];

        foreach ($values as $item) {
            if (is_array($item)) {
                $item = $item['text'] ?? $item['value'] ?? null;
            }

            $normalized = $this->text->key($item);

            if ($normalized !== '') {
                $phrases[] = $normalized;
            }
        }

        return array_values(array_unique($phrases));
    }
}
