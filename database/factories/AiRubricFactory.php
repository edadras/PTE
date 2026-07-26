<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Models\AiRubric;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRubric>
 */
final class AiRubricFactory extends Factory
{
    protected $model = AiRubric::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => $this->academyId(),
            'task_key' => AiTaskKey::SpeakingReadAloud->value,
            'name' => 'Speaking — Read Aloud',
            'version' => 1,
            'is_active' => true,
            // The documented default weighting (docs/06 §4) — and it sums to 100,
            // which the model refuses to save without.
            'criteria' => [
                ['key' => 'pronunciation', 'label' => 'Pronunciation', 'weight' => 30, 'guidance' => ''],
                ['key' => 'fluency', 'label' => 'Oral fluency', 'weight' => 25, 'guidance' => ''],
                ['key' => 'vocabulary', 'label' => 'Word accuracy', 'weight' => 20, 'guidance' => ''],
                ['key' => 'grammar', 'label' => 'Structural fidelity', 'weight' => 15, 'guidance' => ''],
                ['key' => 'content', 'label' => 'Coverage', 'weight' => 10, 'guidance' => ''],
            ],
            'scale_min' => 0,
            'scale_max' => 90,
            'rounding' => AiRubric::ROUNDING_NEAREST,
        ];
    }

    /**
     * @param  array<int, array{key: string, label?: string, weight: int, guidance?: string}>  $criteria
     */
    public function withCriteria(array $criteria): self
    {
        return $this->state(fn (): array => ['criteria' => $criteria]);
    }

    public function forTask(AiTaskKey $task): self
    {
        return $this->state(fn (): array => ['task_key' => $task->value]);
    }

    public function platformDefault(): self
    {
        return $this->state(fn (): array => ['academy_id' => null]);
    }

    private function academyId(): mixed
    {
        return class_exists(Academy::class) && method_exists(Academy::class, 'factory')
            ? Academy::factory()
            : 1;
    }
}
