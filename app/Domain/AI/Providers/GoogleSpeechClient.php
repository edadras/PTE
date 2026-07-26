<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\TranscriptionClient;
use App\Domain\AI\Data\TranscriptionRequest;
use App\Domain\AI\Data\TranscriptionResult;
use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Exceptions\ProviderUnavailableException;

/**
 * Google Cloud Speech-to-Text (synchronous recognize).
 *
 * Synchronous recognition is capped at roughly one minute of audio, which suits
 * every PTE speaking task; longer recordings would need the long-running
 * endpoint and are rejected by the quality gate before they get here.
 */
final class GoogleSpeechClient extends AbstractProviderClient implements TranscriptionClient
{
    public function provider(): AiProvider
    {
        return AiProvider::GoogleStt;
    }

    public function supports(string $modelKey): bool
    {
        return str_starts_with($modelKey, 'google-stt') || str_starts_with($modelKey, 'chirp');
    }

    public function transcribe(TranscriptionRequest $request): TranscriptionResult
    {
        $contents = @file_get_contents($request->wavPath);

        if ($contents === false) {
            throw ProviderUnavailableException::transport(
                $this->provider(),
                $request->modelKey,
                new \RuntimeException("Audio file [{$request->wavPath}] could not be read."),
            );
        }

        $config = [
            'encoding' => 'LINEAR16',
            'sampleRateHertz' => $request->sampleRate,
            'languageCode' => $this->bcp47($request->languageCode),
            'enableWordTimeOffsets' => true,
            'enableWordConfidence' => true,
            'enableAutomaticPunctuation' => true,
            'model' => $this->remoteModel($request->modelKey),
        ];

        if (filled($request->vocabularyHint)) {
            $config['speechContexts'] = [[
                'phrases' => array_slice(
                    preg_split('/\s+/u', (string) $request->vocabularyHint, -1, PREG_SPLIT_NO_EMPTY) ?: [],
                    0,
                    500,
                ),
            ]];
        }

        [$response, $elapsedMs] = $this->send(
            $request->modelKey,
            fn () => $this->http($request->timeoutSeconds)
                ->withHeaders(['x-goog-api-key' => $this->apiKey($request->apiKey)])
                ->post($this->baseUrl().'/speech:recognize', [
                    'config' => $config,
                    'audio' => ['content' => base64_encode($contents)],
                ]),
        );

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        [$text, $confidence, $words] = $this->flattenResults($body);

        return new TranscriptionResult(
            text: $text,
            confidence: $confidence,
            words: $words,
            durationSeconds: $request->durationSeconds,
            provider: $this->provider(),
            modelKey: $request->modelKey,
            latencyMs: $elapsedMs,
            languageCode: $request->languageCode,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: string, 1: float, 2: array<int, array{word: string, start: float, end: float, confidence: float}>}
     */
    private function flattenResults(array $body): array
    {
        $results = $body['results'] ?? [];

        if (! is_array($results)) {
            return ['', 0.0, []];
        }

        $transcript = '';
        $confidences = [];
        $words = [];

        foreach ($results as $result) {
            $alternative = $result['alternatives'][0] ?? null;

            if (! is_array($alternative)) {
                continue;
            }

            $transcript .= ' '.(string) ($alternative['transcript'] ?? '');

            if (isset($alternative['confidence'])) {
                $confidences[] = (float) $alternative['confidence'];
            }

            foreach ((array) ($alternative['words'] ?? []) as $word) {
                if (! is_array($word)) {
                    continue;
                }

                $words[] = [
                    'word' => (string) ($word['word'] ?? ''),
                    'start' => $this->seconds($word['startTime'] ?? null),
                    'end' => $this->seconds($word['endTime'] ?? null),
                    'confidence' => (float) ($word['confidence'] ?? 0.0),
                ];
            }
        }

        $confidence = $confidences === [] ? 0.0 : round(array_sum($confidences) / count($confidences), 4);

        return [trim($transcript), $confidence, $words];
    }

    /** Durations arrive as protobuf strings such as "1.720s". */
    private function seconds(mixed $value): float
    {
        return is_string($value) ? (float) rtrim($value, 's') : (float) $value;
    }

    private function bcp47(string $languageCode): string
    {
        return str_contains($languageCode, '-') ? $languageCode : $languageCode.'-US';
    }

    /** Our catalogue keys are namespaced; the API wants its own short names. */
    private function remoteModel(string $modelKey): string
    {
        return match ($modelKey) {
            'google-stt-latest-long' => 'latest_long',
            'google-stt-latest-short' => 'latest_short',
            default => 'latest_short',
        };
    }
}
