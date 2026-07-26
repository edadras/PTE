<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Exceptions\TenantNotResolvedException;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;

/**
 * Makes a model tenant-scoped: every query is filtered by the active academy and
 * every insert is stamped with it, without the developer having to remember.
 *
 * Fail-closed by design — see TenantNotResolvedException and ADR-002.
 *
 * @property int $academy_id
 *
 * @method static Builder<static> withoutTenantScope()
 * @method static Builder<static> forAcademy(Academy|int $academy)
 */
trait BelongsToAcademy
{
    public static function bootBelongsToAcademy(): void
    {
        static::addGlobalScope('academy', function (Builder $query): void {
            $model = $query->getModel();

            if (TenantContext::check()) {
                $query->where(
                    $model->qualifyColumn($model->getAcademyForeignKey()),
                    TenantContext::id()
                );

                return;
            }

            // Console context (migrations, seeders, platform jobs) is allowed to
            // run unscoped — those paths set the tenant explicitly when needed.
            if (app()->runningInConsole()) {
                return;
            }

            throw TenantNotResolvedException::forModel($model::class);
        });

        static::creating(function (Model $model): void {
            $key = $model->getAcademyForeignKey();

            if (blank($model->getAttribute($key))) {
                $model->setAttribute($key, TenantContext::id());
            }
        });
    }

    public function getAcademyForeignKey(): string
    {
        return 'academy_id';
    }

    public function academy(): BelongsTo
    {
        return $this->belongsTo(Academy::class, $this->getAcademyForeignKey());
    }

    /**
     * Deliberately escape the tenant boundary.
     *
     * Guarded by a gate so that an accidental call still fails for anyone
     * without platform-wide access. Console runs are exempt because the
     * scheduler and maintenance commands have no authenticated user.
     *
     * @return Builder<static>
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        if (! app()->runningInConsole() && Gate::denies('platform.view_all')) {
            abort(403, 'Platform-wide access is required to query across academies.');
        }

        return $query->withoutGlobalScope('academy');
    }

    /**
     * Query a specific academy regardless of the active tenant.
     *
     * @return Builder<static>
     */
    public function scopeForAcademy(Builder $query, Academy|int $academy): Builder
    {
        $id = $academy instanceof Academy ? $academy->getKey() : $academy;

        return $query
            ->withoutGlobalScope('academy')
            ->where($query->getModel()->qualifyColumn($this->getAcademyForeignKey()), $id);
    }
}
