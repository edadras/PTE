<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\Models\PlatformAuditLog;
use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one place an audit row is written.
 *
 * Two behaviours are deliberate:
 *
 *  - **it never throws.** An audit failure must not roll back the business
 *    action that triggered it — a teacher's grade correction that fails because
 *    the log table is full is a worse outcome than a missing log line, and the
 *    failure is escalated to the application log instead;
 *  - **values are redacted and truncated here**, not at every call site. A
 *    before/after diff of a bot row would otherwise put a plaintext token in a
 *    table half the academy can read (docs/12 §3).
 *
 * @see docs/02-roles-and-rbac.md §7
 */
final class AuditRecorder
{
    /** Keys whose value is never written to an audit row, at any nesting level. */
    private const REDACTED_KEYS = [
        'token', 'webhook_secret', 'payments_provider_token', 'password',
        'api_key', 'secret', 'token_hash', 'two_factor_secret', 'remember_token',
    ];

    private const MAX_VALUE_CHARS = 2000;

    /** Explicitly set actor, for console and queue contexts with no Auth user. */
    private ?Model $actor = null;

    private ?string $actorLabelOverride = null;

    /**
     * Pin the actor for subsequent records — a console command acting on behalf
     * of an operator, or a job continuing work a user started.
     */
    public function actingAs(?Model $actor, ?string $label = null): self
    {
        $this->actor = $actor;
        $this->actorLabelOverride = $label;

        return $this;
    }

    public function forgetActor(): void
    {
        $this->actor = null;
        $this->actorLabelOverride = null;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(
        AuditAction|string $action,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        ?int $academyId = null,
    ): ?ActivityLog {
        $academyId ??= $this->resolveAcademyId($subject);

        if ($academyId === null) {
            // Nothing to attribute it to; a tenant row with a guessed academy
            // would be worse than none at all.
            Log::warning('Audit entry dropped: no academy in context.', [
                'action' => $action instanceof AuditAction ? $action->value : $action,
                'subject' => $subject === null ? null : $subject::class,
            ]);

            return null;
        }

        try {
            [$actorType, $actorId, $actorLabel] = $this->resolveActor();

            $log = new ActivityLog;
            $log->forceFill([
                'academy_id' => $academyId,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_label' => $actorLabel,
                'action' => $action instanceof AuditAction ? $action->value : $action,
                'subject_type' => $subject === null ? null : $subject::class,
                'subject_id' => $subject?->getKey(),
                'old_values' => $this->clean($old),
                'new_values' => $this->clean($new),
                'ip' => $this->ip(),
                'user_agent' => $this->userAgent(),
                'created_at' => now(),
            ]);
            $log->save();

            return $log;
        } catch (Throwable $e) {
            Log::error('Failed to write an audit entry.', [
                'action' => $action instanceof AuditAction ? $action->value : $action,
                'academy_id' => $academyId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record a Super Admin action. Written to the platform table *and*, when a
     * specific academy is the target, mirrored into that academy's own trail —
     * a customer asking "who looked at my data" must be able to answer it
     * without platform access.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordPlatform(
        AuditAction|string $action,
        Academy|int|null $target = null,
        array $payload = [],
        bool $mirrorToTenant = true,
    ): ?PlatformAuditLog {
        $targetId = $target instanceof Academy ? (int) $target->getKey() : $target;

        try {
            [, $actorId] = $this->resolveActor();

            $log = new PlatformAuditLog;
            $log->forceFill([
                'user_id' => $this->actor instanceof User ? $this->actor->getKey() : $actorId,
                'action' => $action instanceof AuditAction ? $action->value : $action,
                'target_academy_id' => $targetId,
                'payload' => $this->clean($payload),
                'ip' => $this->ip(),
                'user_agent' => $this->userAgent(),
                'created_at' => now(),
            ]);
            $log->save();

            if ($mirrorToTenant && $targetId !== null) {
                $this->record($action, null, [], $payload, $targetId);
            }

            return $log;
        } catch (Throwable $e) {
            Log::error('Failed to write a platform audit entry.', [
                'action' => $action instanceof AuditAction ? $action->value : $action,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Diff two attribute sets and record only what actually changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordChange(
        AuditAction|string $action,
        Model $subject,
        array $before,
        array $after,
        ?int $academyId = null,
    ): ?ActivityLog {
        $changedKeys = array_keys(array_filter(
            $after,
            static fn (mixed $value, string $key): bool => ! array_key_exists($key, $before) || $before[$key] !== $value,
            ARRAY_FILTER_USE_BOTH
        ));

        if ($changedKeys === []) {
            return null;
        }

        return $this->record(
            $action,
            $subject,
            array_intersect_key($before, array_flip($changedKeys)),
            array_intersect_key($after, array_flip($changedKeys)),
            $academyId,
        );
    }

    /**
     * @return array{0: string|null, 1: int|null, 2: string|null}
     */
    private function resolveActor(): array
    {
        $actor = $this->actor ?? (Auth::hasUser() ? Auth::user() : null);

        if (! $actor instanceof Model) {
            return [app()->runningInConsole() ? 'system' : null, null, $this->actorLabelOverride];
        }

        $type = match (true) {
            $actor instanceof User => 'user',
            $actor instanceof Student => 'student',
            default => Str::snake(class_basename($actor)),
        };

        return [$type, (int) $actor->getKey(), $this->actorLabelOverride ?? $this->labelFor($actor)];
    }

    private function labelFor(Model $actor): ?string
    {
        if ($actor instanceof User) {
            return (string) $actor->getAttribute('name');
        }

        if ($actor instanceof Student) {
            return $actor->fullName();
        }

        return null;
    }

    private function resolveAcademyId(?Model $subject): ?int
    {
        $fromSubject = $subject?->getAttribute('academy_id');

        if (is_numeric($fromSubject)) {
            return (int) $fromSubject;
        }

        if ($subject instanceof Academy) {
            return (int) $subject->getKey();
        }

        return TenantContext::idOrNull();
    }

    private function ip(): ?string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        try {
            return Request::ip();
        } catch (Throwable) {
            return null;
        }
    }

    private function userAgent(): ?string
    {
        try {
            $agent = Request::userAgent();
        } catch (Throwable) {
            return null;
        }

        return is_string($agent) ? Str::limit($agent, 250, '') : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|null
     */
    private function clean(array $values): ?array
    {
        if ($values === []) {
            return null;
        }

        $cleaned = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $cleaned[$key] = '[redacted]';

                continue;
            }

            $cleaned[$key] = match (true) {
                is_array($value) => $this->clean($value) ?? [],
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
                is_string($value) => Str::limit($value, self::MAX_VALUE_CHARS, ''),
                is_scalar($value), $value === null => $value,
                default => (string) Str::limit((string) json_encode($value), self::MAX_VALUE_CHARS, ''),
            };
        }

        return $cleaned;
    }

    private function isSensitive(string $key): bool
    {
        $needle = Str::lower($key);

        foreach (self::REDACTED_KEYS as $sensitive) {
            if (str_contains($needle, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
