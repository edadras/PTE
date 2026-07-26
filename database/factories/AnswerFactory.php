<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Assessment\Enums\ScoredBy;
use App\Domain\Assessment\Enums\ScoringStatus;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Learning\Enums\QuestionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Answer>
 */
final class AnswerFactory extends Factory
{
    protected $model = Answer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_type' => SessionType::Practice,
            'session_id' => 1,
            // question_id and student_id belong to contexts this factory must not
            // reach into; tests set them to rows they created themselves.
            'question_id' => 1,
            'student_id' => 1,
            'answer_data' => ['question_type' => QuestionType::WriteFromDictation->value],
            'max_score' => 90,
            'scoring_status' => ScoringStatus::Pending,
        ];
    }

    public function forSession(SessionType $type, int $sessionId): self
    {
        return $this->state(fn (): array => [
            'session_type' => $type,
            'session_id' => $sessionId,
        ]);
    }

    public function forQuestion(int $questionId, ?QuestionType $type = null): self
    {
        return $this->state(function (array $attributes) use ($questionId, $type): array {
            $payload = $attributes['answer_data'] ?? [];

            if ($type !== null) {
                $payload['question_type'] = $type->value;
            }

            return ['question_id' => $questionId, 'answer_data' => $payload];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function withPayload(array $payload): self
    {
        return $this->state(fn (array $attributes): array => [
            'answer_data' => array_merge($attributes['answer_data'] ?? [], $payload),
        ]);
    }

    public function scored(float $score, float $maxScore = 90, ScoredBy $by = ScoredBy::System): self
    {
        return $this->state(fn (): array => [
            'score' => $score,
            'max_score' => $maxScore,
            'scoring_status' => ScoringStatus::Scored,
            'scored_by' => $by,
            'scored_at' => now(),
            'confidence' => 1.0,
        ]);
    }

    public function awaitingAi(): self
    {
        return $this->state(fn (): array => [
            'scoring_status' => ScoringStatus::Pending,
            'scored_by' => null,
            'score' => null,
        ]);
    }
}
