<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

/**
 * Objective, free, deterministic acoustic facts.
 *
 * Handing these to the model instead of asking it to guess them is what makes a
 * cheap model good enough for speaking (docs/06 §5, step 5).
 */
final readonly class AudioMetrics
{
    /**
     * @param  array<int, array{start_ms: int, end_ms: int, duration_ms: int}>  $pauses
     */
    public function __construct(
        public float $durationSeconds,
        public float $speechSeconds,
        public float $rms,
        public float $peak,
        public int $pauseCount,
        public int $pauseTotalMs,
        public array $pauses = [],
        public ?int $wordCount = null,
        public ?float $wordsPerMinute = null,
        public int $sampleRate = 16000,
    ) {}

    public static function empty(): self
    {
        return new self(0.0, 0.0, 0.0, 0.0, 0, 0);
    }

    public function speechRatio(): float
    {
        return $this->durationSeconds > 0.0
            ? round($this->speechSeconds / $this->durationSeconds, 4)
            : 0.0;
    }

    public function longestPauseMs(): int
    {
        return $this->pauses === [] ? 0 : max(array_column($this->pauses, 'duration_ms'));
    }

    /**
     * WPM only becomes computable once the transcript exists, so the analyzer
     * produces the acoustic half first and the ASR step completes it.
     */
    public function withWordCount(int $wordCount): self
    {
        $minutes = $this->speechSeconds > 0.0 ? $this->speechSeconds / 60.0 : 0.0;

        return new self(
            durationSeconds: $this->durationSeconds,
            speechSeconds: $this->speechSeconds,
            rms: $this->rms,
            peak: $this->peak,
            pauseCount: $this->pauseCount,
            pauseTotalMs: $this->pauseTotalMs,
            pauses: $this->pauses,
            wordCount: $wordCount,
            wordsPerMinute: $minutes > 0.0 ? round($wordCount / $minutes, 1) : null,
            sampleRate: $this->sampleRate,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'duration_seconds' => round($this->durationSeconds, 3),
            'speech_seconds' => round($this->speechSeconds, 3),
            'rms' => round($this->rms, 6),
            'peak' => round($this->peak, 6),
            'pause_count' => $this->pauseCount,
            'pause_total_ms' => $this->pauseTotalMs,
            'longest_pause_ms' => $this->longestPauseMs(),
            'speech_ratio' => $this->speechRatio(),
            'word_count' => $this->wordCount,
            'words_per_minute' => $this->wordsPerMinute,
            'sample_rate' => $this->sampleRate,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var array<int, array{start_ms: int, end_ms: int, duration_ms: int}> $pauses */
        $pauses = is_array($payload['pauses'] ?? null) ? $payload['pauses'] : [];

        return new self(
            durationSeconds: (float) ($payload['duration_seconds'] ?? 0),
            speechSeconds: (float) ($payload['speech_seconds'] ?? 0),
            rms: (float) ($payload['rms'] ?? 0),
            peak: (float) ($payload['peak'] ?? 0),
            pauseCount: (int) ($payload['pause_count'] ?? 0),
            pauseTotalMs: (int) ($payload['pause_total_ms'] ?? 0),
            pauses: $pauses,
            wordCount: isset($payload['word_count']) ? (int) $payload['word_count'] : null,
            wordsPerMinute: isset($payload['words_per_minute']) ? (float) $payload['words_per_minute'] : null,
            sampleRate: (int) ($payload['sample_rate'] ?? 16000),
        );
    }
}
