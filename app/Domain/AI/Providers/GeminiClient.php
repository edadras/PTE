<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\AiProviderClient;
use App\Domain\AI\Data\AiCompletionRequest;
use App\Domain\AI\Data\AiCompletionResponse;
use App\Domain\AI\Enums\AiProvider;

/**
 * Google Generative Language API.
 *
 * @see https://ai.google.dev/api/generate-content
 */
final class GeminiClient extends AbstractProviderClient implements AiProviderClient
{
    /** Keys the responseSchema field accepts; anything else 400s. */
    private const SCHEMA_KEYS = [
        'type', 'format', 'description', 'nullable', 'enum',
        'items', 'properties', 'required',
    ];

    public function provider(): AiProvider
    {
        return AiProvider::Gemini;
    }

    public function supports(string $modelKey): bool
    {
        return str_starts_with($modelKey, 'gemini-');
    }

    public function complete(AiCompletionRequest $request): AiCompletionResponse
    {
        $url = sprintf('%s/models/%s:generateContent', $this->baseUrl(), $request->modelKey);

        [$response, $elapsedMs] = $this->send(
            $request->modelKey,
            fn () => $this->http($request->timeoutSeconds)
                // Gemini takes the key as a header; keeping it out of the URL
                // keeps it out of proxy and access logs.
                ->withHeaders(['x-goog-api-key' => $this->apiKey($request->apiKey)])
                ->post($url, $this->payload($request)),
        );

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $text = $this->concatenateParts($body);
        $usage = is_array($body['usageMetadata'] ?? null) ? $body['usageMetadata'] : [];

        return new AiCompletionResponse(
            text: $text,
            parsedJson: $this->extractJson($text),
            promptTokens: (int) ($usage['promptTokenCount'] ?? 0),
            completionTokens: (int) ($usage['candidatesTokenCount'] ?? 0),
            latencyMs: $elapsedMs,
            modelKey: $request->modelKey,
            provider: $this->provider(),
            requestId: $response->header('x-request-id') ?: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AiCompletionRequest $request): array
    {
        $generationConfig = [
            'temperature' => $request->temperature,
            'maxOutputTokens' => $request->maxOutputTokens,
        ];

        if ($request->expectsJson()) {
            $generationConfig['responseMimeType'] = 'application/json';
            $generationConfig['responseSchema'] = $this->pruneSchema($request->outputSchema, self::SCHEMA_KEYS);
        }

        return [
            'systemInstruction' => [
                'parts' => [['text' => $request->systemPrompt]],
            ],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $request->userPrompt]]],
            ],
            'generationConfig' => $generationConfig,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function concatenateParts(array $body): string
    {
        $parts = $body['candidates'][0]['content']['parts'] ?? [];

        if (! is_array($parts)) {
            return '';
        }

        $text = '';

        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        return $text;
    }
}
