<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\AiProviderClient;
use App\Domain\AI\Data\AiCompletionRequest;
use App\Domain\AI\Data\AiCompletionResponse;
use App\Domain\AI\Enums\AiProvider;

/**
 * OpenAI Chat Completions.
 *
 * @see https://platform.openai.com/docs/api-reference/chat
 */
final class OpenAiClient extends AbstractProviderClient implements AiProviderClient
{
    private const SCHEMA_KEYS = [
        'type', 'description', 'enum', 'items', 'properties', 'required',
        'minimum', 'maximum', 'additionalProperties',
    ];

    public function provider(): AiProvider
    {
        return AiProvider::OpenAi;
    }

    public function supports(string $modelKey): bool
    {
        return str_starts_with($modelKey, 'gpt-') || str_starts_with($modelKey, 'o');
    }

    public function complete(AiCompletionRequest $request): AiCompletionResponse
    {
        [$response, $elapsedMs] = $this->send(
            $request->modelKey,
            fn () => $this->http($request->timeoutSeconds)
                ->withToken($this->apiKey($request->apiKey))
                ->post($this->baseUrl().'/chat/completions', $this->payload($request)),
        );

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $text = (string) ($body['choices'][0]['message']['content'] ?? '');
        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new AiCompletionResponse(
            text: $text,
            parsedJson: $this->extractJson($text),
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            latencyMs: $elapsedMs,
            modelKey: $request->modelKey,
            provider: $this->provider(),
            requestId: is_string($body['id'] ?? null) ? $body['id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AiCompletionRequest $request): array
    {
        $payload = [
            'model' => $request->modelKey,
            'messages' => [
                ['role' => 'system', 'content' => $request->systemPrompt],
                ['role' => 'user', 'content' => $request->userPrompt],
            ],
            'max_completion_tokens' => $request->maxOutputTokens,
        ];

        // The reasoning generation rejects any temperature but the default;
        // sending 0.3 there is a 400, not a nudge.
        if ($this->supportsTemperature($request->modelKey)) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->expectsJson()) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => str_replace('.', '_', $request->task->value),
                    'schema' => $this->pruneSchema($request->outputSchema, self::SCHEMA_KEYS),
                    'strict' => false,
                ],
            ];
        }

        return $payload;
    }

    private function supportsTemperature(string $modelKey): bool
    {
        return ! str_starts_with($modelKey, 'gpt-5')
            && ! str_starts_with($modelKey, 'o1')
            && ! str_starts_with($modelKey, 'o3');
    }
}
