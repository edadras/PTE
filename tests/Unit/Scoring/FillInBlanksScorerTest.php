<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Scoring\Scorers\FillInBlanksScorer;
use App\Domain\Learning\Enums\QuestionType;

final class FillInBlanksScorerTest extends ScorerTestCase
{
    public function test_it_supports_the_three_fill_in_blanks_types(): void
    {
        $scorer = new FillInBlanksScorer;

        $this->assertTrue($scorer->supports(QuestionType::FillInBlanksListening));
        $this->assertTrue($scorer->supports(QuestionType::FillInBlanksReading));
        $this->assertTrue($scorer->supports(QuestionType::FillInBlanksReadingWriting));
        $this->assertFalse($scorer->supports(QuestionType::ReorderParagraphs));
    }

    public function test_every_blank_correct_scores_full_marks(): void
    {
        $result = (new FillInBlanksScorer)->score($this->fib(['research', 'evidence', 'conclusion']));

        $this->assertScore(self::MAX, $result->score);
        $this->assertSame(3, $result->breakdown['correct']);
    }

    public function test_two_of_four_blanks_earn_half(): void
    {
        $answer = $this->answer(
            QuestionType::FillInBlanksReading,
            ['blanks' => ['research', 'wrong', 'conclusion', 'nope']],
            correctAnswer: ['blanks' => ['research', 'evidence', 'conclusion', 'method']],
        );

        $result = (new FillInBlanksScorer)->score($answer);

        $this->assertScore(self::MAX / 2, $result->score);
        $this->assertSame(2, $result->breakdown['correct']);
        $this->assertSame(4, $result->breakdown['total']);
    }

    public function test_a_blank_may_declare_several_acceptable_fillings(): void
    {
        $answer = $this->answer(
            QuestionType::FillInBlanksListening,
            ['blanks' => ['Color']],
            correctAnswer: ['blanks' => [['colour', 'color']]],
        );

        $this->assertScore(self::MAX, (new FillInBlanksScorer)->score($answer)->score);
    }

    public function test_an_empty_answer_scores_zero(): void
    {
        $result = (new FillInBlanksScorer)->score($this->fib([]));

        $this->assertScore(0.0, $result->score);
        $this->assertSame('assessment.feedback.no_answer', $result->feedback['label']);
    }

    public function test_a_question_without_blanks_is_flagged_for_review(): void
    {
        $answer = $this->answer(QuestionType::FillInBlanksReading, ['blanks' => ['a']]);

        $this->assertSame(0.0, (new FillInBlanksScorer)->score($answer)->confidence);
    }

    /**
     * @param  array<int, string>  $submitted
     */
    private function fib(array $submitted): Answer
    {
        return $this->answer(
            QuestionType::FillInBlanksReadingWriting,
            ['blanks' => $submitted],
            correctAnswer: ['blanks' => ['research', 'evidence', 'conclusion']],
        );
    }
}
