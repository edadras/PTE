<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Scoring\Scorers\ReorderParagraphsScorer;
use App\Domain\Learning\Enums\QuestionType;

final class ReorderParagraphsScorerTest extends ScorerTestCase
{
    /** Correct order C → A → D → B, so three adjacent pairs are available. */
    private const CORRECT = ['C', 'A', 'D', 'B'];

    public function test_it_supports_only_reorder_paragraphs(): void
    {
        $scorer = new ReorderParagraphsScorer;

        $this->assertTrue($scorer->supports(QuestionType::ReorderParagraphs));
        $this->assertFalse($scorer->supports(QuestionType::FillInBlanksReading));
    }

    public function test_the_correct_order_scores_every_pair(): void
    {
        $result = (new ReorderParagraphsScorer)->score($this->ro(['C', 'A', 'D', 'B']));

        $this->assertScore(self::MAX, $result->score);
        $this->assertSame(3, $result->breakdown['correct_pairs']);
        $this->assertSame(3, $result->breakdown['total_pairs']);
    }

    public function test_one_correct_adjacent_pair_earns_one_third(): void
    {
        // C→A survives; A→B and B→D do not.
        $result = (new ReorderParagraphsScorer)->score($this->ro(['C', 'A', 'B', 'D']));

        $this->assertSame(1, $result->breakdown['correct_pairs']);
        $this->assertScore(self::MAX / 3, $result->score);
    }

    public function test_a_fully_reversed_order_earns_nothing(): void
    {
        $result = (new ReorderParagraphsScorer)->score($this->ro(['B', 'D', 'A', 'C']));

        $this->assertScore(0.0, $result->score);
        $this->assertSame(0, $result->breakdown['correct_pairs']);
    }

    public function test_an_empty_answer_scores_zero(): void
    {
        $result = (new ReorderParagraphsScorer)->score($this->ro([]));

        $this->assertScore(0.0, $result->score);
        $this->assertSame('assessment.feedback.no_answer', $result->feedback['label']);
    }

    public function test_a_question_without_a_correct_order_is_flagged_for_review(): void
    {
        $answer = $this->answer(QuestionType::ReorderParagraphs, ['order' => ['A', 'B']]);

        $this->assertSame(0.0, (new ReorderParagraphsScorer)->score($answer)->confidence);
    }

    /**
     * @param  array<int, string>  $order
     */
    private function ro(array $order): Answer
    {
        return $this->answer(
            QuestionType::ReorderParagraphs,
            ['order' => $order],
            correctAnswer: ['correct_order' => self::CORRECT],
        );
    }
}
