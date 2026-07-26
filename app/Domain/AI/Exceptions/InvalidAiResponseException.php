<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use RuntimeException;

/**
 * The model answered, but not with what the schema demanded.
 *
 * Distinct from ProviderUnavailableException because the recovery differs: one
 * retry against the *same* model first (docs/06 §8), then fall through.
 */
final class InvalidAiResponseException extends RuntimeException
{
    /**
     * @param  array<int, string>  $violations
     */
    private function __construct(
        string $message,
        public readonly array $violations = [],
        public readonly ?string $rawExcerpt = null,
    ) {
        parent::__construct($message);
    }

    public static function notJson(string $raw): self
    {
        return new self('Model output was not valid JSON.', ['not_json'], self::excerpt($raw));
    }

    /**
     * @param  array<int, string>  $violations
     */
    public static function schemaMismatch(array $violations, ?string $raw = null): self
    {
        return new self(
            'Model output did not match the required schema: '.implode('; ', $violations),
            $violations,
            $raw === null ? null : self::excerpt($raw),
        );
    }

    public static function emptyOutput(): self
    {
        return new self('Model returned an empty response.', ['empty']);
    }

    /** Never carry the whole response around: it may hold student text. */
    private static function excerpt(string $raw): string
    {
        return mb_substr($raw, 0, 300);
    }
}
