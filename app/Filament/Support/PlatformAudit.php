<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Writer and reader for `platform_audit_logs` (docs/02 §7).
 *
 * The table is not part of the migrations that shipped with the domain layer,
 * so this degrades to a structured log line rather than silently dropping the
 * record — an un-audited super-admin action is exactly what docs/02 forbids.
 * See the panel README note: the migration is still owed.
 */
final class PlatformAudit
{
    public const TABLE = 'platform_audit_logs';

    public const ACTION_IMPERSONATE_START = 'platform.impersonate.start';

    public const ACTION_IMPERSONATE_END = 'platform.impersonate.end';

    public const ACTION_ACADEMY_CREATE = 'platform.academies.create';

    public const ACTION_ACADEMY_SUSPEND = 'platform.academies.suspend';

    public const ACTION_ACADEMY_RESUME = 'platform.academies.resume';

    public const ACTION_ACADEMY_CLONE = 'platform.academies.clone';

    private static ?bool $tableExists = null;

    public static function isAvailable(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }

        try {
            return self::$tableExists = Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            return self::$tableExists = false;
        }
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public static function record(
        string $action,
        ?User $actor = null,
        ?int $academyId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $oldValues = [],
        array $newValues = [],
    ): void {
        $request = request();

        $row = [
            'academy_id' => $academyId,
            'actor_type' => $actor === null ? null : $actor::class,
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'old_values' => $oldValues === [] ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE),
            'new_values' => $newValues === [] ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ];

        if (self::isAvailable()) {
            try {
                DB::table(self::TABLE)->insert($row);

                return;
            } catch (Throwable $e) {
                Log::error('Platform audit write failed.', ['action' => $action, 'error' => $e->getMessage()]);
            }
        }

        Log::channel(config('logging.default'))->warning('platform-audit', $row);
    }

    /**
     * @return Builder|null Null when the table does not exist yet.
     */
    public static function query(): ?Builder
    {
        return self::isAvailable() ? DB::table(self::TABLE) : null;
    }

    /** Tests migrate mid-process; the memoised answer must not outlive that. */
    public static function flush(): void
    {
        self::$tableExists = null;
    }
}
