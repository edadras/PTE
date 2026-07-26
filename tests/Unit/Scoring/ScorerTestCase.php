<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Domain\Assessment\Data\QuestionContext;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Learning\Enums\QuestionType;
use PHPUnit\Framework\TestCase;

/**
 * Scorers are pure, so these run as plain PHPUnit tests with no application
 * boot and no database — which is the point of keeping grading rules out of the
 * models.
 */
abstract class ScorerTestCase extends TestCase
{
    protected const MAX = 90.0;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $content
     * @param  array<array-key, mixed>  $correctAnswer
     */
    protected function answer(
        QuestionType $type,
        array $payload = [],
        array $content = [],
        array $correctAnswer = [],
        ?string $transcript = null,
    ): Answer {
        // forceFill, not the constructor: mass assignment consults the schema and
        // would need a database connection these tests deliberately do not have.
        $answer = (new Answer)->forceFill([
            'answer_data' => $payload,
            'max_score' => self::MAX,
            'transcript' => $transcript,
        ]);

        return $answer->attachContext(new QuestionContext($type, $content, $correctAnswer, self::MAX));
    }

    protected function assertScore(float $expected, float $actual, string $message = ''): void
    {
        $this->assertEqualsWithDelta($expected, $actual, 0.01, $message);
    }
}
