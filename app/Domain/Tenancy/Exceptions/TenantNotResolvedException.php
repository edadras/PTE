<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown whenever tenant-scoped data is touched without a resolved tenant.
 *
 * This is deliberately fail-closed: the alternative (silently skipping the
 * scope) turns a single middleware bug into a full cross-tenant data leak.
 *
 * @see docs/01-multi-tenancy.md — ADR-002
 */
final class TenantNotResolvedException extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(
            "No tenant resolved while querying [{$model}]. Resolve a tenant first, "
            .'or use withoutTenantScope() if this is an intentional platform-level query.'
        );
    }

    public static function forOperation(string $operation): self
    {
        return new self("No tenant resolved while performing [{$operation}].");
    }
}
