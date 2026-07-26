<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\TranscriptionClient;
use App\Domain\AI\Data\TranscriptionRequest;
use App\Domain\AI\Data\TranscriptionResult;
use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Exceptions\ProviderUnavailableException;

/**
 * Whisper ASR over the OpenAI-compatible /audio/transcriptions endpoint (the
 * same shape self-hosted whisper servers expose, which is why the base URL is
 * configurable per environment).
 */
final class WhisperClient extends AbstractProviderClient implements TranscriptionClient
{
    public function provider(): AiProvider
    {
        return AiProvider::Whisper;
    }

    public function supports(string $modelKey): bool
    {
        return str_contains($modelKey, 'whisper');
    }

    public function transcribe(TranscriptionRequest $request): TranscriptionResult
    {
        if (! is_readable($request->wavPath)) {
            throw ProviderUnavailableException::transport(
                $this->provider(),
                $request->modelKey,
                new \RuntimeException("Audio file [{$request->wavPath}] is not readable."),
            );
        }

        $handle = fopen($request->wavPath, 'r');

        if ($handle === false) {
            throw ProviderUnavailableException::transport(
                $this->provider(),
                $request->modelKey,
                new \RuntimeException("Audio file [{$request->wavPath}] could not be opened."),
            );
        }

        $form = [
            ['name' => 'model', 'contents' => $request->modelKey],
            ['name' => 'language', 'contents' => $request->languageCode],
            ['name' => 'response_format', 'contents' => 'verbose_json'],
            ['name' => 'timestamp_granularities[]', 'contents' => 'word'],
            ['name' => 'timestamp_granularities[]', 'contents' => 'segment'],
        ];

        // A vocabulary hint biases decoding toward the target wording; it is a
        // hint only, never spliced into the transcript, or word error rate
        // would be meaningless.
        if (filled($request->vocabularyHint)) {
            $form[] = ['name' => 'prompt', 'contents' => mb_substr((string) $request->vocabularyHint, 0, 800)];
        }

        [$response, $elapsedMs] = $this->send(
            $request->modelKey,
            fn () => $this->http($request->timeoutSeconds)
                ->withToken($this->apiKey($request->apiKey))
                ->attach('file', $handle, basename($request->wavPath))
                ->asMultipart()
                ->post($this->baseUrl().'/audio/transcriptions', $form),
        );

        if (is_resource($handle)) {
            fclose($handle);
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return new TranscriptionResult(
            text: trim((string) ($body['text'] ?? '')),
            confidence: $this->confidenceFromSegments($body),
            words: $this->words($body),
            durationSeconds: (float) ($body['duration'] ?? $request->durationSeconds),
            provider: $this->provider(),
            modelKey: $request->modelKey,
            latencyMs: $elapsedMs,
            languageCode: (string) ($body['language'] ?? $request->languageCode),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<int, array{word: string, start: float, end: float, confidence: float}>
     */
    private function words(array $body): array
    {
        $words = $body['words'] ?? [];

        if (! is_array($words)) {
            return [];
        }

        $mapped = [];

        foreach ($words as $word) {
            if (! is_array($word) || ! isset($word['word'])) {
                continue;
            }

            $mapped[] = [
                'word' => (string) $word['word'],
                'start' => (float) ($word['start'] ?? 0),
                'end' => (float) ($word['end'] ?? 0),
                // Whisper reports no per-word probability; the segment-level
                // figure is the honest approximation.
                'confidence' => (float) ($word['probability'] ?? 0.0),
            ];
        }

        return $mapped;
    }

    /**
     * Whisper exposes log-probabilities per segment, not a confidence. exp() of
     * the mean is the conventional translation into 0..1.
     *
     * @param  array<string, mixed>  $body
     */
    private function confidenceFromSegments(array $body): float
    {
        $segments = $body['segments'] ?? [];

        if (! is_array($segments) || $segments === []) {
            return 0.0;
        }

        $logProbs = [];
        $noSpeech = [];

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            if (isset($segment['avg_logprob'])) {
                $logProbs[] = (float) $segment['avg_logprob'];
            }

            if (isset($segment['no_speech_prob'])) {
                $noSpeech[] = (float) $segment['no_speech_prob'];
            }
        }

        if ($logProbs === []) {
            return 0.0;
        }

        $confidence = exp(array_sum($logProbs) / count($logProbs));

        if ($noSpeech !== []) {
            $confidence *= (1.0 - min(1.0, array_sum($noSpeech) / count($noSpeech)));
        }

        return round(max(0.0, min(1.0, $confidence)), 4);
    }
}
