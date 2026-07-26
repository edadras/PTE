<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Enums\AiTaskKey;

/**
 * Everything a provider client needs, and nothing vendor-specific.
 *
 * The API key travels on the request rather than being read from config by the
 * client, because a BYOK academy overrides the platform key per call.
 */
final readonly class AiCompletionRequest
{
    /**
     * @param  array<string, mixed>  $outputSchema
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public AiTaskKey $task,
        public AiProvider $provider,
        public string $modelKey,
        public string $systemPrompt,
        public string $userPrompt,
        public array $outputSchema = [],
        public float $temperature = 0.3,
        public int $maxOutputTokens = 1200,
        public int $timeoutSeconds = 60,
        public ?string $apiKey = null,
        public ?int $academyId = null,
        public ?int $studentId = null,
        public ?int $answerId = null,
        public ?int $promptVersion = null,
        public array $metadata = [],
    ) {}

    /** Re-point the same prompt at the next model in the fallback chain. */
    public function withModel(AiProvider $provider, string $modelKey, ?string $apiKey = null): self
    {
        return new self(
            task: $this->task,
            provider: $provider,
            modelKey: $modelKey,
            systemPrompt: $this->systemPrompt,
            userPrompt: $this->userPrompt,
            outputSchema: $this->outputSchema,
            temperature: $this->temperature,
            maxOutputTokens: $this->maxOutputTokens,
            timeoutSeconds: $this->timeoutSeconds,
            apiKey: $apiKey ?? $this->apiKey,
            academyId: $this->academyId,
            studentId: $this->studentId,
            answerId: $this->answerId,
            promptVersion: $this->promptVersion,
            metadata: $this->metadata,
        );
    }

    /**
     * Identity of the *work*, not of the call. Two academies asking the same
     * model the same question with the same settings may share a cached answer
     * only inside their own tenant namespace — see PromptCache.
     */
    public function contentHash(): string
    {
        return hash('sha256', implode('|', [
            $this->task->value,
            $this->provider->value,
            $this->modelKey,
            $this->systemPrompt,
            $this->userPrompt,
            json_encode($this->outputSchema, JSON_THROW_ON_ERROR),
            (string) $this->temperature,
            (string) $this->maxOutputTokens,
        ]));
    }

    /** Deterministic sampling is the only sane setting for scoring. */
    public function isDeterministic(): bool
    {
        return $this->temperature <= 0.0;
    }

    public function expectsJson(): bool
    {
        return $this->outputSchema !== [];
    }

    /** The rendered prompt is only ever persisted in ai_logs, never in ai_requests. */
    public function renderedForLog(): string
    {
        return "### SYSTEM\n{$this->systemPrompt}\n\n### USER\n{$this->userPrompt}";
    }
}
