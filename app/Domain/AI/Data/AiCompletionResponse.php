<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

use App\Domain\AI\Enums\AiProvider;

/**
 * The single shape every provider is flattened into. Anything upstream of the
 * Providers/ folder only ever sees this.
 */
final readonly class AiCompletionResponse
{
    /**
     * @param  array<string, mixed>|null  $parsedJson
     */
    public function __construct(
        public string $text,
        public ?array $parsedJson,
        public int $promptTokens,
        public int $completionTokens,
        public int $latencyMs,
        public string $modelKey,
        public AiProvider $provider,
        public bool $cacheHit = false,
        public bool $fallbackUsed = false,
        public ?string $requestId = null,
        public ?int $aiRequestId = null,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    public function withParsedJson(array $parsed): self
    {
        return $this->copy(parsedJson: $parsed);
    }

    public function withCacheHit(bool $cacheHit = true): self
    {
        return $this->copy(cacheHit: $cacheHit);
    }

    public function withFallbackUsed(bool $fallbackUsed = true): self
    {
        return $this->copy(fallbackUsed: $fallbackUsed);
    }

    public function withAiRequestId(int $aiRequestId): self
    {
        return $this->copy(aiRequestId: $aiRequestId);
    }

    /**
     * @return array<string, mixed>
     */
    public function scores(): array
    {
        $scores = $this->parsedJson['scores'] ?? [];

        return is_array($scores) ? $scores : [];
    }

    public function confidence(): ?float
    {
        $value = $this->parsedJson['confidence'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function feedback(): array
    {
        $feedback = $this->parsedJson['feedback'] ?? [];

        return is_array($feedback) ? $feedback : [];
    }

    /**
     * @param  array<string, mixed>|null  $parsedJson
     */
    private function copy(
        ?array $parsedJson = null,
        ?bool $cacheHit = null,
        ?bool $fallbackUsed = null,
        ?int $aiRequestId = null,
    ): self {
        return new self(
            text: $this->text,
            parsedJson: $parsedJson ?? $this->parsedJson,
            promptTokens: $this->promptTokens,
            completionTokens: $this->completionTokens,
            latencyMs: $this->latencyMs,
            modelKey: $this->modelKey,
            provider: $this->provider,
            cacheHit: $cacheHit ?? $this->cacheHit,
            fallbackUsed: $fallbackUsed ?? $this->fallbackUsed,
            requestId: $this->requestId,
            aiRequestId: $aiRequestId ?? $this->aiRequestId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'parsed_json' => $this->parsedJson,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens(),
            'latency_ms' => $this->latencyMs,
            'model_key' => $this->modelKey,
            'provider' => $this->provider->value,
            'cache_hit' => $this->cacheHit,
            'fallback_used' => $this->fallbackUsed,
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            text: (string) ($payload['text'] ?? ''),
            parsedJson: is_array($payload['parsed_json'] ?? null) ? $payload['parsed_json'] : null,
            promptTokens: (int) ($payload['prompt_tokens'] ?? 0),
            completionTokens: (int) ($payload['completion_tokens'] ?? 0),
            latencyMs: (int) ($payload['latency_ms'] ?? 0),
            modelKey: (string) ($payload['model_key'] ?? ''),
            provider: AiProvider::from((string) $payload['provider']),
            cacheHit: (bool) ($payload['cache_hit'] ?? false),
            fallbackUsed: (bool) ($payload['fallback_used'] ?? false),
            requestId: $payload['request_id'] ?? null,
        );
    }
}
