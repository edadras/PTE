<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Scoring\Scorers\HighlightIncorrectWordsScorer;
use App\Domain\Learning\Enums\QuestionType;

final class HighlightIncorrectWordsScorerTest extends ScorerTestCase
{
    /** @var array<int, string> */
    private const EXPECTED = ['rapid', 'pressure', 'worldwide'];

    public function test_it_supports_only_highlight_incorrect_words(): void
    {
        $scorer = new HighlightIncorrectWordsScorer;

        $this->assertTrue($scorer->supports(QuestionType::HighlightIncorrectWords));
        $this->assertFalse($scorer->supports(QuestionType::MultipleChoiceListening));
    }

    public function test_all_three_words_score_full_marks(): void
    {
        $result = (new HighlightIncorrectWordsScorer)->score($this->hiw(['rapid', 'pressure', 'worldwide']));

        $this->assertScore(self::MAX, $result->score);
        $this->assertSame(3, $result->breakdown['hits']);
        $this->assertSame(0, $result->breakdown['false_positives']);
    }

    public function test_two_of_three_words_earn_two_thirds(): void
    {
        $result = (new HighlightIncorrectWordsScorer)->score($this->hiw(['rapid', 'pressure']));

        $this->assertScore(self::MAX * 2 / 3, $result->score);
        $this->assertSame(['worldwide'], $result->breakdown['missed']);
    }

    public function test_a_wrong_pick_cancels_a_correct_one(): void
    {
        // 2 hits − 1 false positive = 1 of 3.
        $result = (new HighlightIncorrectWordsScorer)->score($this->hiw(['rapid', 'pressure', 'growth']));

        $this->assertScore(self::MAX / 3, $result->score);
        $this->assertSame(1, $result->breakdown['false_positives']);
    }

    public function test_selecting_everything_is_floored_at_zero_and_never_negative(): void
    {
        $result = (new HighlightIncorrectWordsScorer)->score($this->hiw([
            'the', 'rapid', 'growth', 'of', 'urban', 'populations', 'pressure', 'worldwide',
        ]));

        $this->assertScore(0.0, $result->score);
        $this->assertGreaterThanOrEqual(0.0, $result->score);
        $this->assertTrue($result->feedback['over_selected']);
    }

    public function test_an_empty_selection_scores_zero(): void
    {
        $result = (new HighlightIncorrectWordsScorer)->score($this->hiw([]));

        $this->assertScore(0.0, $result->score);
        $this->assertSame('assessment.feedback.no_answer', $result->feedback['label']);
    }

    /**
     * @param  array<int, string>  $selected
     */
    private function hiw(array $selected): Answer
    {
        return $this->answer(
            QuestionType::HighlightIncorrectWords,
            ['options' => $selected],
            correctAnswer: ['incorrect_words' => self::EXPECTED],
        );
    }
}
