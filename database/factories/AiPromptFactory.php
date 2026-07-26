<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Enums\PromptStatus;
use App\Domain\AI\Models\AiPrompt;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPrompt>
 */
final class AiPromptFactory extends Factory
{
    protected $model = AiPrompt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => $this->academyId(),
            'key' => AiTaskKey::SpeakingReadAloud->value,
            'version' => 1,
            'status' => PromptStatus::Published->value,
            'system_prompt' => 'You are an experienced PTE examiner. Score strictly.',
            'user_template' => "Target text:\n{{question_text}}\n\nTranscript:\n{{transcript}}",
            'output_schema' => [
                'type' => 'object',
                'required' => ['scores', 'confidence'],
                'properties' => [
                    'scores' => ['type' => 'object'],
                    'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                ],
            ],
            'variables' => ['question_text', 'transcript'],
            'model_hint' => null,
            'tested_at' => now(),
            'published_at' => now(),
        ];
    }

    public function forTask(AiTaskKey $task): self
    {
        return $this->state(fn (): array => ['key' => $task->value]);
    }

    public function draft(): self
    {
        return $this->state(fn (): array => [
            'status' => PromptStatus::Draft->value,
            'published_at' => null,
        ]);
    }

    /**
     * A platform-default row. Model events must be suppressed by the caller,
     * because BelongsToAcademy stamps the active tenant on create and a
     * platform default has none.
     */
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
