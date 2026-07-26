<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

use App\Domain\AI\Enums\AiProvider;

final readonly class TranscriptionRequest
{
    public function __construct(
        public string $wavPath,
        public AiProvider $provider,
        public string $modelKey,
        public string $languageCode = 'en',
        public int $sampleRate = 16000,
        public float $durationSeconds = 0.0,
        public int $timeoutSeconds = 60,
        public ?string $apiKey = null,
        public ?int $academyId = null,
        public ?int $answerId = null,
        /**
         * Target text for read-aloud / repeat-sentence. Passed as a decoding
         * hint only — it must never be allowed to become the transcript, or the
         * word error rate would always be zero.
         */
        public ?string $vocabularyHint = null,
    ) {}

    public function durationMinutes(): float
    {
        return $this->durationSeconds / 60.0;
    }

    public function withModel(AiProvider $provider, string $modelKey, ?string $apiKey = null): self
    {
        return new self(
            wavPath: $this->wavPath,
            provider: $provider,
            modelKey: $modelKey,
            languageCode: $this->languageCode,
            sampleRate: $this->sampleRate,
            durationSeconds: $this->durationSeconds,
            timeoutSeconds: $this->timeoutSeconds,
            apiKey: $apiKey ?? $this->apiKey,
            academyId: $this->academyId,
            answerId: $this->answerId,
            vocabularyHint: $this->vocabularyHint,
        );
    }
}
