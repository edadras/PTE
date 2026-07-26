<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

use App\Domain\AI\Enums\AiProvider;

/**
 * @phpstan-type WordTiming array{word: string, start: float, end: float, confidence: float}
 */
final readonly class TranscriptionResult
{
    /**
     * @param  array<int, array{word: string, start: float, end: float, confidence: float}>  $words
     */
    public function __construct(
        public string $text,
        public float $confidence,
        public array $words,
        public float $durationSeconds,
        public AiProvider $provider,
        public string $modelKey,
        public int $latencyMs = 0,
        public string $languageCode = 'en',
    ) {}

    public function wordCount(): int
    {
        if ($this->words !== []) {
            return count($this->words);
        }

        return count(preg_split('/\s+/u', trim($this->text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    public function billableMinutes(): float
    {
        return $this->durationSeconds / 60.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'confidence' => $this->confidence,
            'words' => $this->words,
            'duration_seconds' => $this->durationSeconds,
            'provider' => $this->provider->value,
            'model_key' => $this->modelKey,
            'latency_ms' => $this->latencyMs,
            'language_code' => $this->languageCode,
        ];
    }
}
