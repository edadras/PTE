<?php

declare(strict_types=1);

namespace App\Domain\Audit\Concerns;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * Automatic before/after capture for models whose every change is auditable.
 *
 * Opt-in per model rather than global: `answers` and `telegram_messages` change
 * millions of times a year and auditing them would cost more storage than the
 * data itself. Use it on configuration-shaped models — bots, prompts, rubrics,
 * roles — where the question "who changed this and what was it before" is worth
 * a row.
 *
 * A model may narrow what is captured:
 *
 *   protected array $auditable = ['is_active', 'webhook_url'];
 *   protected array $auditExcept = ['pending_update_count'];
 *
 * Values are redacted and truncated by AuditRecorder, so a secret column that
 * slips into $auditable still cannot leak.
 *
 * @see docs/02-roles-and-rbac.md §7
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(static function (Model $model): void {
            $model->writeAuditEntry(AuditAction::ModelCreated, [], $model->auditableAttributes($model->getAttributes()));
        });

        static::updated(static function (Model $model): void {
            $changed = $model->auditableAttributes($model->getChanges());

            if ($changed === []) {
                return;
            }

            $original = array_intersect_key(
                $model->auditableAttributes($model->getRawOriginal()),
                $changed,
            );

            $model->writeAuditEntry(AuditAction::ModelUpdated, $original, $changed);
        });

        static::deleted(static function (Model $model): void {
            $model->writeAuditEntry(AuditAction::ModelDeleted, $model->auditableAttributes($model->getAttributes()), []);
        });
    }

    /**
     * Override to log a domain-specific action instead of the generic ones.
     */
    public function auditActionFor(AuditAction $default): AuditAction|string
    {
        return $default;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditableAttributes(array $attributes): array
    {
        /** @var array<int, string> $only */
        $only = property_exists($this, 'auditable') ? $this->auditable : [];

        /** @var array<int, string> $except */
        $except = property_exists($this, 'auditExcept') ? $this->auditExcept : [];

        // Never worth a row: the tenant is implied and the timestamps are the
        // audit row's own created_at.
        $except = [...$except, 'academy_id', 'created_at', 'updated_at'];

        if ($only !== []) {
            $attributes = array_intersect_key($attributes, array_flip($only));
        }

        return array_diff_key($attributes, array_flip($except));
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function writeAuditEntry(AuditAction $action, array $old, array $new): void
    {
        if ($old === [] && $new === []) {
            return;
        }

        app(AuditRecorder::class)->record(
            $this->auditActionFor($action),
            $this,
            $old,
            $new,
        );
    }
}
