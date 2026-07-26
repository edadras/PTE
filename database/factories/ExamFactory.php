<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Assessment\Enums\ExamStatus;
use App\Domain\Assessment\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exam>
 */
final class ExamFactory extends Factory
{
    protected $model = Exam::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // academy_id is intentionally absent: BelongsToAcademy stamps it from the
        // active tenant, and setting it here would hide a missing TenantContext.
        return [
            'title' => 'PTE Mock Exam '.$this->faker->unique()->numberBetween(1, 9999),
            'description' => $this->faker->sentence(),
            'duration_minutes' => 120,
            'total_score' => 90,
            'passing_score' => 65,
            'rules' => [
                'ordered_sections' => true,
                'allow_back' => false,
                'shuffle_questions' => true,
                'show_timer' => true,
                'autosave' => true,
                'instant_result' => false,
                'require_teacher_approval' => false,
            ],
            'availability' => ['max_attempts' => 1],
            'status' => ExamStatus::Draft,
            'published_at' => null,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'status' => ExamStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function forAcademy(int $academyId): self
    {
        return $this->state(fn (): array => ['academy_id' => $academyId]);
    }
}
