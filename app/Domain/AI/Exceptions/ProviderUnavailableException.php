<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Enums\AiProvider;
use RuntimeException;
use Throwable;

/**
 * A provider could not serve the request: transport error, 5xx, timeout, rate
 * limit or an open circuit breaker. Always retryable in principle, which is why
 * the fallback chain catches it and moves to the next model.
 */
final class ProviderUnavailableException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly ?AiProvider $provider = null,
        public readonly ?string $modelKey = null,
        public readonly ?string $errorCode = null,
        public readonly bool $rateLimited = false,
        public readonly bool $timedOut = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function transport(AiProvider $provider, string $modelKey, Throwable $previous): self
    {
        return new self(
            "Provider [{$provider->value}] could not be reached for model [{$modelKey}].",
            $provider,
            $modelKey,
            'transport_error',
            previous: $previous,
        );
    }

    public static function httpError(AiProvider $provider, string $modelKey, int $status, string $body): self
    {
        return new self(
            "Provider [{$provider->value}] returned HTTP {$status} for model [{$modelKey}].",
            $provider,
            $modelKey,
            'http_'.$status,
            rateLimited: $status === 429,
            timedOut: $status === 408 || $status === 504,
        );
    }

    public static function timeout(AiProvider $provider, string $modelKey, ?Throwable $previous = null): self
    {
        return new self(
            "Provider [{$provider->value}] timed out for model [{$modelKey}].",
            $provider,
            $modelKey,
            'timeout',
            timedOut: true,
            previous: $previous,
        );
    }

    public static function circuitOpen(AiProvider $provider, string $modelKey): self
    {
        return new self(
            "Circuit breaker is open for [{$provider->value}/{$modelKey}].",
            $provider,
            $modelKey,
            'circuit_open',
        );
    }

    public static function notConfigured(AiProvider $provider): self
    {
        return new self(
            "No API key is configured for provider [{$provider->value}].",
            $provider,
            null,
            'not_configured',
        );
    }

    public static function chainExhausted(string $taskKey, int $attempts): self
    {
        return new self(
            "Every model in the fallback chain for [{$taskKey}] failed after {$attempts} attempt(s).",
            null,
            null,
            'chain_exhausted',
        );
    }

    public static function noModelAvailable(string $taskKey): self
    {
        return new self(
            "No active model is configured for task [{$taskKey}].",
            null,
            null,
            'no_model',
        );
    }
}
