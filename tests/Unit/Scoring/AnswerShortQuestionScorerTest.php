<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Domain\Assessment\Scoring\Scorers\AnswerShortQuestionScorer;
use App\Domain\Learning\Enums\QuestionType;

final class AnswerShortQuestionScorerTest extends ScorerTestCase
{
    public function test_it_supports_only_answer_short_question(): void
    {
        $scorer = new AnswerShortQuestionScorer;

        $this->assertTrue($scorer->supports(QuestionType::AnswerShortQuestion));
        $this->assertFalse($scorer->supports(QuestionType::ReadAloud));
    }

    public function test_an_accepted_answer_inside_the_transcript_scores_full_marks(): void
    {
        $answer = $this->answer(
            QuestionType::AnswerShortQuestion,
            correctAnswer: ['accepted_answers' => ['a thermometer', 'thermometer']],
            transcript: 'I think it is a thermometer.',
        );

        $result = (new AnswerShortQuestionScorer)->score($answer);

        $this->assertScore(self::MAX, $result->score);
        $this->assertSame('assessment.feedback.correct', $result->feedback['label']);
    }

    public function test_a_wrong_answer_scores_nothing(): void
    {
        $answer = $this->answer(
            QuestionType::AnswerShortQuestion,
            correctAnswer: ['accepted_answers' => ['thermometer']],
            transcript: 'a barometer',
        );

        $this->assertScore(0.0, (new AnswerShortQuestionScorer)->score($answer)->score);
    }

    public function test_keyword_questions_award_partial_credit(): void
    {
        $answer = $this->answer(
            QuestionType::AnswerShortQuestion,
            correctAnswer: ['keywords' => ['nitrogen', 'oxygen']],
            transcript: 'Mostly nitrogen and some argon.',
        );

        $result = (new AnswerShortQuestionScorer)->score($answer);

        $this->assertScore(self::MAX / 2, $result->score);
        $this->assertSame(['nitrogen'], $result->breakdown['matched']);
        $this->assertSame(['oxygen'], $result->breakdown['missed']);
    }

    public function test_a_word_is_matched_whole_and_not_as_a_substring(): void
    {
        $answer = $this->answer(
            QuestionType::AnswerShortQuestion,
            correctAnswer: ['accepted_answers' => ['ten']],
            transcript: 'It happens often.',
        );

        $this->assertScore(0.0, (new AnswerShortQuestionScorer)->score($answer)->score);
    }

    public function test_a_silent_recording_scores_zero(): void
    {
        $answer = $this->answer(
            QuestionType::AnswerShortQuestion,
            correctAnswer: ['accepted_answers' => ['thermometer']],
            transcript: '',
        );

        $result = (new AnswerShortQuestionScorer)->score($answer);

        $this->assertScore(0.0, $result->score);
        $this->assertSame('assessment.feedback.no_speech', $result->feedback['label']);
    }

    public function test_a_question_without_an_expected_answer_is_flagged_for_review(): void
    {
        $answer = $this->answer(
            QuestionType::AnswerShortQuestion,
            transcript: 'anything at all',
        );

        $this->assertSame(0.0, (new AnswerShortQuestionScorer)->score($answer)->confidence);
    }
}
