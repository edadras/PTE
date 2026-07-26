<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\AiProviderClient;
use App\Domain\AI\Data\AiCompletionRequest;
use App\Domain\AI\Data\AiCompletionResponse;
use App\Domain\AI\Enums\AiProvider;

/**
 * Anthropic Messages API.
 *
 * @see https://docs.anthropic.com/en/api/messages
 */
final class AnthropicClient extends AbstractProviderClient implements AiProviderClient
{
    private const SCHEMA_KEYS = [
        'type', 'description', 'enum', 'items', 'properties', 'required',
        'minimum', 'maximum',
    ];

    public function provider(): AiProvider
    {
        return AiProvider::Anthropic;
    }

    public function supports(string $modelKey): bool
    {
        return str_starts_with($modelKey, 'claude-');
    }

    public function complete(AiCompletionRequest $request): AiCompletionResponse
    {
        [$response, $elapsedMs] = $this->send(
            $request->modelKey,
            fn () => $this->http($request->timeoutSeconds)
                ->withHeaders([
                    'x-api-key' => $this->apiKey($request->apiKey),
                    'anthropic-version' => (string) config('pte.ai.providers.anthropic.version', '2023-06-01'),
                ])
                ->post($this->baseUrl().'/messages', $this->payload($request)),
        );

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $text = $this->extractText($body);
        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new AiCompletionResponse(
            text: $text,
            parsedJson: $this->extractJson($text),
            promptTokens: (int) ($usage['input_tokens'] ?? 0),
            completionTokens: (int) ($usage['output_tokens'] ?? 0),
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
            'system' => $request->systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $request->userPrompt],
            ],
            'max_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
        ];

        // There is no response_format here; structured output is obtained by
        // declaring a single tool and forcing its use.
        if ($request->expectsJson()) {
            $payload['tools'] = [[
                'name' => 'emit_result',
                'description' => 'Return the scoring result in the required structure.',
                'input_schema' => $this->pruneSchema($request->outputSchema, self::SCHEMA_KEYS) + ['type' => 'object'],
            ]];
            $payload['tool_choice'] = ['type' => 'tool', 'name' => 'emit_result'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractText(array $body): string
    {
        $blocks = $body['content'] ?? [];

        if (! is_array($blocks)) {
            return '';
        }

        $text = '';

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'tool_use' && is_array($block['input'] ?? null)) {
                return json_encode($block['input'], JSON_UNESCAPED_UNICODE) ?: '';
            }

            if (isset($block['text']) && is_string($block['text'])) {
                $text .= $block['text'];
            }
        }

        return $text;
    }
}
