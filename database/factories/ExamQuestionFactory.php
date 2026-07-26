<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Assessment\Models\ExamQuestion;
use App\Domain\Assessment\Models\ExamSection;
use Database\Factories\Concerns\ResolvesAcademy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamQuestion>
 */
final class ExamQuestionFactory extends Factory
{
    use ResolvesAcademy;

    protected $model = ExamQuestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => $this->resolveAcademy(),
            'exam_section_id' => ExamSection::factory(),
            // question_id belongs to the Learning context, which this factory
            // must not reach into; tests set it to a row they created themselves.
            'question_id' => 1,
            'sort_order' => 0,
            'score' => null,
        ];
    }

    public function forQuestion(int $questionId, int $sortOrder = 0): self
    {
        return $this->state(fn (): array => [
            'question_id' => $questionId,
            'sort_order' => $sortOrder,
        ]);
    }

    public function worth(float $score): self
    {
        return $this->state(fn (): array => ['score' => $score]);
    }
}
