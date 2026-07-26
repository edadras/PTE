<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Scoring\Scorers\WriteFromDictationScorer;
use App\Domain\Learning\Enums\QuestionType;

final class WriteFromDictationScorerTest extends ScorerTestCase
{
    private const TRANSCRIPT = 'The university library will be closed on Monday.';

    public function test_it_supports_only_write_from_dictation(): void
    {
        $scorer = new WriteFromDictationScorer;

        $this->assertTrue($scorer->supports(QuestionType::WriteFromDictation));
        $this->assertFalse($scorer->supports(QuestionType::Essay));
    }

    public function test_a_perfect_transcription_scores_full_marks(): void
    {
        $result = (new WriteFromDictationScorer)->score($this->wfd('The university library will be closed on Monday.'));

        $this->assertScore(self::MAX, $result->score);
        $this->assertSame(8, $result->breakdown['correct_words']);
        $this->assertSame(8, $result->breakdown['total_words']);
        $this->assertTrue($result->isPerfect());
    }

    public function test_capitalisation_and_punctuation_are_ignored(): void
    {
        $result = (new WriteFromDictationScorer)->score($this->wfd('the university library will be closed on monday'));

        $this->assertScore(self::MAX, $result->score);
    }

    public function test_a_partial_transcription_earns_proportional_credit(): void
    {
        // Six of the eight words survive, in order.
        $result = (new WriteFromDictationScorer)->score($this->wfd('The university will be closed Monday'));

        $this->assertSame(6, $result->breakdown['correct_words']);
        $this->assertScore(self::MAX * 6 / 8, $result->score);
        $this->assertContains('library', $result->breakdown['missing']);
    }

    public function test_scrambled_words_do_not_earn_full_marks(): void
    {
        $result = (new WriteFromDictationScorer)->score($this->wfd('Monday on closed be will library university The'));

        $this->assertLessThan(self::MAX, $result->score);
    }

    public function test_an_empty_answer_scores_zero(): void
    {
        $result = (new WriteFromDictationScorer)->score($this->wfd(''));

        $this->assertScore(0.0, $result->score);
        $this->assertSame('assessment.feedback.no_answer', $result->feedback['label']);
        $this->assertScore(self::MAX, $result->maxScore);
    }

    public function test_a_question_without_a_transcript_is_flagged_for_review(): void
    {
        $answer = $this->answer(QuestionType::WriteFromDictation, ['text' => 'anything']);

        $result = (new WriteFromDictationScorer)->score($answer);

        $this->assertSame(0.0, $result->confidence);
    }

    private function wfd(string $submitted): Answer
    {
        return $this->answer(
            QuestionType::WriteFromDictation,
            ['text' => $submitted],
            ['transcript' => self::TRANSCRIPT],
        );
    }
}
