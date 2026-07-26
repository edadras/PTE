<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Enums\AiRequestStatus;
use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Models\AiRequest;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiRequest>
 */
final class AiRequestFactory extends Factory
{
    protected $model = AiRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $promptTokens = $this->faker->numberBetween(400, 3000);
        $completionTokens = $this->faker->numberBetween(80, 900);

        return [
            'academy_id' => $this->academyId(),
            'student_id' => null,
            'answer_id' => null,
            'task_key' => AiTaskKey::SpeakingReadAloud->value,
            'provider' => AiProvider::Gemini->value,
            'model_key' => 'gemini-2.5-pro',
            'prompt_version' => 1,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'cost_usd' => round($promptTokens / 1_000_000 * 1.25 + $completionTokens / 1_000_000 * 10.0, 6),
            'currency' => 'USD',
            'latency_ms' => $this->faker->numberBetween(400, 9000),
            'status' => AiRequestStatus::Success->value,
            'cache_hit' => false,
            'fallback_used' => false,
            'byok' => false,
            'error_code' => null,
            'request_id' => (string) Str::uuid(),
            'created_at' => now(),
        ];
    }

    public function failed(string $errorCode = 'http_500'): self
    {
        return $this->state(fn (): array => [
            'status' => AiRequestStatus::Failed->value,
            'error_code' => $errorCode,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'cost_usd' => 0,
        ]);
    }

    public function cached(): self
    {
        return $this->state(fn (): array => ['cache_hit' => true, 'cost_usd' => 0, 'latency_ms' => 2]);
    }

    private function academyId(): mixed
    {
        return class_exists(Academy::class) && method_exists(Academy::class, 'factory')
            ? Academy::factory()
            : 1;
    }
}
