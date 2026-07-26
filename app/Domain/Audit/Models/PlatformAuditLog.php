<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Tenancy\Models\Academy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Super Admin actions across tenants.
 *
 * Not tenant-scoped, on purpose (CONVENTIONS §3): this table exists to record
 * what the *platform* did to an academy — impersonation, exports, forced
 * suspension — and an academy-scoped view of it would let the tenant see only
 * the entries that mention them while hiding the rest, which is the opposite of
 * what an oversight log is for.
 *
 * Append-only for the same reason as ActivityLog.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property int|null $target_academy_id
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 *
 * @see docs/02-roles-and-rbac.md §7
 */
final class PlatformAuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'target_academy_id' => 'integer',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('platform_audit_logs is append-only.');
    }

    public function delete(): bool
    {
        throw new LogicException('platform_audit_logs is append-only.');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Academy, $this> */
    public function targetAcademy(): BelongsTo
    {
        return $this->belongsTo(Academy::class, 'target_academy_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAcademy(Builder $query, Academy|int $academy): Builder
    {
        return $query->where('target_academy_id', $academy instanceof Academy ? $academy->getKey() : $academy);
    }
}
