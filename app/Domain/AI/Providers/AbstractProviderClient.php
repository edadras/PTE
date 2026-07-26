<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Exceptions\ProviderUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared plumbing for every vendor client: config lookup, timing, and the
 * translation of "however this vendor failed" into ProviderUnavailableException.
 *
 * Everything vendor-specific — endpoints, payload shapes, token accounting —
 * stays in the concrete subclasses, which together with AiProvider are the only
 * code in the application allowed to know a vendor exists (ADR-005).
 */
abstract class AbstractProviderClient
{
    abstract public function provider(): AiProvider;

    protected function baseUrl(): string
    {
        return rtrim((string) config("pte.ai.providers.{$this->provider()->configKey()}.base_url", ''), '/');
    }

    /** BYOK key wins over the platform key; neither is ever logged. */
    protected function apiKey(?string $override = null): string
    {
        $key = $override ?? config("pte.ai.providers.{$this->provider()->configKey()}.api_key");

        if (! is_string($key) || $key === '') {
            throw ProviderUnavailableException::notConfigured($this->provider());
        }

        return $key;
    }

    protected function http(int $timeoutSeconds): PendingRequest
    {
        return Http::timeout($timeoutSeconds)
            ->connectTimeout(min(10, $timeoutSeconds))
            ->acceptJson()
            ->withoutRedirecting();
    }

    /**
     * @param  callable(): Response  $callback
     * @return array{0: Response, 1: int} response and elapsed milliseconds
     */
    protected function send(string $modelKey, callable $callback): array
    {
        $startedAt = hrtime(true);

        try {
            $response = $callback();
        } catch (ConnectionException $e) {
            // Guzzle reports read timeouts as connection exceptions; the
            // distinction matters for the circuit breaker and the ledger.
            throw str_contains(strtolower($e->getMessage()), 'timed out')
                ? ProviderUnavailableException::timeout($this->provider(), $modelKey, $e)
                : ProviderUnavailableException::transport($this->provider(), $modelKey, $e);
        }

        $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if ($response->failed()) {
            throw ProviderUnavailableException::httpError(
                $this->provider(),
                $modelKey,
                $response->status(),
                mb_substr($response->body(), 0, 500),
            );
        }

        return [$response, $elapsedMs];
    }

    /**
     * Pull a JSON object out of model output that may be wrapped in prose or a
     * ```json fence. Returns null when there is nothing parseable — the
     * validator turns that into a retry, not a crash.
     *
     * @return array<string, mixed>|null
     */
    protected function extractJson(string $text): ?array
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*(.*?)```/s', $trimmed, $matches) === 1) {
            $trimmed = trim($matches[1]);
        }

        $decoded = json_decode($trimmed, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Reduce our JSON Schema to the OpenAPI-ish subset most vendors accept.
     * Anything exotic is dropped rather than risking a 400 — the response is
     * validated in PHP afterwards regardless.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<int, string>  $allowed
     * @return array<string, mixed>
     */
    protected function pruneSchema(array $schema, array $allowed): array
    {
        $result = [];

        foreach ($schema as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            if ($key === 'properties' && is_array($value)) {
                $result['properties'] = array_map(
                    fn (mixed $sub): mixed => is_array($sub) ? $this->pruneSchema($sub, $allowed) : $sub,
                    $value,
                );

                continue;
            }

            if ($key === 'items' && is_array($value)) {
                $result['items'] = $this->pruneSchema($value, $allowed);

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
