<?php

declare(strict_types=1);

namespace App\Domain\Integration\Exceptions;

use RuntimeException;

/**
 * The API key authenticated, but is not allowed to do this.
 *
 * Distinct from a plain 403 so an integrator sees *which* scope is missing and
 * can re-issue the key instead of guessing.
 *
 * @see docs/08-api-and-integrations.md §2
 */
final class InsufficientScopeException extends RuntimeException
{
    private function __construct(string $message, public readonly string $scope)
    {
        parent::__construct($message);
    }

    public static function forScope(string $scope): self
    {
        return new self(__('api.errors.insufficient_scope', ['scope' => $scope]), $scope);
    }
}
