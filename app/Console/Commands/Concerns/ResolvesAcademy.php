<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Domain\Tenancy\Models\Academy;

/**
 * Every ops command takes "the academy" as either an id or a slug, because that
 * is what the operator has in front of them — a slug from a support ticket, an
 * id from a log line. Making each command choose one would guarantee the wrong
 * one is used at 3am.
 *
 * Soft-deleted academies are included on purpose: exporting or inspecting a
 * deleted tenant during its 30-day retention window is exactly when these
 * commands matter most (docs/01 §8).
 */
trait ResolvesAcademy
{
    protected function findAcademy(string|int|null $reference, bool $withTrashed = true): ?Academy
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $query = Academy::query()->withoutGlobalScopes();

        if (! $withTrashed) {
            $query->whereNull('deleted_at');
        }

        if (is_numeric($reference)) {
            $academy = (clone $query)->whereKey((int) $reference)->first();

            if ($academy instanceof Academy) {
                return $academy;
            }
        }

        return $query->where('slug', (string) $reference)->first();
    }

    /** Resolve or explain, so no command has to repeat the error message. */
    protected function requireAcademy(string|int|null $reference, bool $withTrashed = true): ?Academy
    {
        $academy = $this->findAcademy($reference, $withTrashed);

        if (! $academy instanceof Academy) {
            $this->components->error(__('reports.console.academy_not_found', ['reference' => (string) $reference]));
        }

        return $academy;
    }
}
