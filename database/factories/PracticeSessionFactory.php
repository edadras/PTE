<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Assessment\Enums\SessionStatus;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PracticeSession>
 */
final class PracticeSessionFactory extends Factory
{
    protected $model = PracticeSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => 1,
            'module_key' => ModuleKey::PteListening,
            'question_type' => QuestionType::WriteFromDictation,
            'status' => SessionStatus::InProgress,
            'total_questions' => 5,
            'answered' => 0,
            'total_score' => null,
            'max_score' => null,
            'question_ids' => [],
            'started_at' => now(),
        ];
    }

    public function forStudent(int $studentId): self
    {
        return $this->state(fn (): array => ['student_id' => $studentId]);
    }

    public function ofType(QuestionType $type): self
    {
        return $this->state(fn (): array => [
            'question_type' => $type,
            'module_key' => $type->module(),
        ]);
    }

    /**
     * @param  array<int, int>  $questionIds
     */
    public function withQuestions(array $questionIds): self
    {
        return $this->state(fn (): array => [
            'question_ids' => $questionIds,
            'total_questions' => count($questionIds),
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => SessionStatus::Completed,
            'completed_at' => now(),
            'duration_seconds' => 300,
        ]);
    }
}
