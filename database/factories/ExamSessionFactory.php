<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Assessment\Enums\SessionStatus;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamSession>
 */
final class ExamSessionFactory extends Factory
{
    protected $model = ExamSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'student_id' => 1,
            'attempt_number' => 1,
            'status' => SessionStatus::InProgress,
            'current_section_id' => null,
            'snapshot' => [
                'exam' => [
                    'id' => 1,
                    'title' => 'PTE Mock Exam',
                    'duration_minutes' => 120,
                    'total_score' => 90.0,
                    'passing_score' => 65.0,
                    'rules' => [],
                ],
                'sections' => [],
                'question_count' => 0,
                'taken_at' => now()->toIso8601String(),
                'version' => 1,
            ],
            'started_at' => now(),
            'expires_at' => now()->addMinutes(120),
        ];
    }

    public function forStudent(int $studentId): self
    {
        return $this->state(fn (): array => ['student_id' => $studentId]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     */
    public function withSnapshotSections(array $sections): self
    {
        return $this->state(function (array $attributes) use ($sections): array {
            $snapshot = $attributes['snapshot'] ?? [];
            $snapshot['sections'] = $sections;
            $snapshot['question_count'] = array_sum(array_map(
                static fn (array $section): int => count((array) ($section['questions'] ?? [])),
                $sections
            ));

            return ['snapshot' => $snapshot];
        });
    }

    public function expired(): self
    {
        return $this->state(fn (): array => [
            'started_at' => now()->subMinutes(180),
            'expires_at' => now()->subMinutes(60),
        ]);
    }

    public function submitted(): self
    {
        return $this->state(fn (): array => [
            'status' => SessionStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }
}
