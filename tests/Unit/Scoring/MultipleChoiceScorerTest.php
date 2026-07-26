<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Domain\Assessment\Scoring\Scorers\MultipleChoiceScorer;
use App\Domain\Learning\Enums\QuestionType;

final class MultipleChoiceScorerTest extends ScorerTestCase
{
    public function test_it_supports_the_three_single_answer_types(): void
    {
        $scorer = new MultipleChoiceScorer;

        $this->assertTrue($scorer->supports(QuestionType::MultipleChoiceListening));
        $this->assertTrue($scorer->supports(QuestionType::MultipleChoiceReading));
        $this->assertTrue($scorer->supports(QuestionType::SelectMissingWord));
        $this->assertFalse($scorer->supports(QuestionType::HighlightIncorrectWords));
    }

    public function test_the_correct_option_scores_full_marks(): void
    {
        $answer = $this->answer(
            QuestionType::MultipleChoiceReading,
            ['option' => 'B'],
            correctAnswer: ['option' => 'B'],
        );

        $result = (new MultipleChoiceScorer)->score($answer);

        $this->assertScore(self::MAX, $result->score);
        $this->assertTrue($result->breakdown['correct']);
    }

    public function test_option_keys_are_compared_case_insensitively(): void
    {
        $answer = $this->answer(
            QuestionType::MultipleChoiceListening,
            ['selected' => 'b'],
            correctAnswer: ['option' => 'B'],
        );

        $this->assertScore(self::MAX, (new MultipleChoiceScorer)->score($answer)->score);
    }

    public function test_a_wrong_option_scores_nothing_at_all(): void
    {
        $answer = $this->answer(
            QuestionType::SelectMissingWord,
            ['option' => 'C'],
            correctAnswer: ['option' => 'B'],
        );

        $result = (new MultipleChoiceScorer)->score($answer);

        // Single-answer items have no partial credit: it is 90 or 0.
        $this->assertScore(0.0, $result->score);
        $this->assertFalse($result->breakdown['correct']);
    }

    public function test_an_empty_answer_scores_zero(): void
    {
        $answer = $this->answer(
            QuestionType::MultipleChoiceReading,
            [],
            correctAnswer: ['option' => 'B'],
        );

        $result = (new MultipleChoiceScorer)->score($answer);

        $this->assertScore(0.0, $result->score);
        $this->assertSame('assessment.feedback.no_answer', $result->feedback['label']);
    }

    public function test_a_question_without_a_key_is_flagged_for_review(): void
    {
        $answer = $this->answer(QuestionType::MultipleChoiceReading, ['option' => 'A']);

        $this->assertSame(0.0, (new MultipleChoiceScorer)->score($answer)->confidence);
    }
}
