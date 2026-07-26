<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\AI\Models\AiLog;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Reporting\Enums\ReportStatus;
use App\Domain\Reporting\Models\Report;
use App\Domain\Telegram\Models\TelegramMessage;
use App\Domain\Telegram\Models\TelegramUpdate;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Enforces `config('pte.retention.*')` and the per-academy
 * `academy_settings.data_retention_days`.
 *
 * A platform sweep, never a tenant one: retention is a platform promise, and an
 * academy must not be able to keep its own audit trail alive past the window or
 * to delete it early (see ActivityLog's append-only note).
 *
 * Deletion is bounded per run. A first sweep against a table that has never been
 * pruned can face tens of millions of rows, and an unbounded DELETE would hold
 * locks long enough to look like an outage.
 *
 * @see docs/10-infrastructure-and-ops.md §4 · docs/07-database-schema.md §11
 */
final class RetentionSweeper
{
    private const CHUNK = 1000;

    /** Rows removed from any one table in a single run. */
    private const MAX_PER_TABLE = 100_000;

    /**
     * @return array<string, int> what was removed, keyed by concern
     */
    public function sweep(?Carbon $now = null, bool $dryRun = false): array
    {
        $now ??= now();

        return [
            'telegram_updates' => $this->pruneByAge(
                TelegramUpdate::query()->withoutGlobalScope('academy'),
                $now->copy()->subDays($this->days('telegram_updates', 30)),
                $dryRun,
            ),
            'telegram_messages' => $this->pruneByAge(
                TelegramMessage::query()->withoutGlobalScope('academy'),
                $now->copy()->subDays($this->days('telegram_messages', 90)),
                $dryRun,
            ),
            'ai_logs' => $this->pruneByAge(
                AiLog::query()->withoutGlobalScope('academy'),
                $now->copy()->subDays($this->days('ai_logs', 7)),
                $dryRun,
            ),
            'activity_logs' => $this->pruneActivityLogs(
                $now->copy()->subDays($this->days('activity_logs', 365)),
                $dryRun,
            ),
            'reports' => $this->pruneReports($now, $dryRun),
            'answer_media' => $this->pruneAnswerMedia($now, $dryRun),
        ];
    }

    public function days(string $key, int $default): int
    {
        return max(1, (int) config("pte.retention.{$key}", $default));
    }

    /**
     * The window an academy has chosen for its own student media, capped by the
     * platform default so a tenant cannot opt into keeping voice recordings
     * forever (docs/12).
     */
    public function mediaRetentionDaysFor(Academy $academy): int
    {
        $platformDefault = max(1, (int) config('pte.media.answer_retention_days', 90));
        $configured = $academy->settings?->data_retention_days;

        if (! is_numeric($configured)) {
            return $platformDefault;
        }

        return max(1, min((int) $configured, $platformDefault));
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function pruneByAge(Builder $query, Carbon $before, bool $dryRun): int
    {
        $scoped = $query->where('created_at', '<', $before);

        if ($dryRun) {
            return (int) (clone $scoped)->count();
        }

        $deleted = 0;

        do {
            $affected = (clone $scoped)->limit(self::CHUNK)->delete();
            $deleted += $affected;
        } while ($affected > 0 && $deleted < self::MAX_PER_TABLE);

        return $deleted;
    }

    private function pruneActivityLogs(Carbon $before, bool $dryRun): int
    {
        if ($dryRun) {
            return ActivityLog::query()
                ->withoutGlobalScope('academy')
                ->where('created_at', '<', $before)
                ->count();
        }

        // Routed through the model's single sanctioned removal path, not a
        // generic delete, so the append-only rule keeps exactly one exception.
        return ActivityLog::pruneOlderThan($before, null, self::CHUNK);
    }

    /**
     * Expire the row and drop the object. The row survives with status
     * `expired`: the audit question "who exported the student list in March" is
     * answered by the row, not by the file (docs/02 §7).
     */
    private function pruneReports(Carbon $now, bool $dryRun): int
    {
        $reports = Report::query()
            ->withoutGlobalScope('academy')
            ->expired($now)
            ->where('status', '!=', ReportStatus::Expired->value)
            ->limit(self::CHUNK)
            ->get();

        if ($dryRun) {
            return $reports->count();
        }

        $academies = $this->academiesFor($reports->pluck('academy_id')->unique()->all());
        $removed = 0;

        foreach ($reports as $report) {
            $academy = $academies[(int) $report->academy_id] ?? null;

            if ($academy instanceof Academy) {
                // The tenant disk root is set per academy, so the file can only
                // be addressed from inside its own tenant.
                TenantContext::runFor($academy, static function () use ($report): void {
                    $report->deleteFile();
                });
            }

            $report->forceFill([
                'status' => ReportStatus::Expired,
                'file_path' => null,
                'file_size' => null,
            ])->save();

            $removed++;
        }

        return $removed;
    }

    /**
     * Student voice recordings past the academy's window. The answer row and its
     * score stay; only the audio goes, which is what the retention promise is
     * actually about (docs/10 §4).
     */
    private function pruneAnswerMedia(Carbon $now, bool $dryRun): int
    {
        $removed = 0;

        Academy::query()
            ->withoutGlobalScopes()
            ->with('settings')
            ->chunkById(50, function ($academies) use ($now, $dryRun, &$removed): void {
                foreach ($academies as $academy) {
                    $removed += TenantContext::runFor(
                        $academy,
                        fn (): int => $this->pruneMediaForAcademy($academy, $now, $dryRun)
                    );
                }
            });

        return $removed;
    }

    private function pruneMediaForAcademy(Academy $academy, Carbon $now, bool $dryRun): int
    {
        $before = $now->copy()->subDays($this->mediaRetentionDaysFor($academy));

        $answers = Answer::query()
            ->whereNotNull('media_path')
            ->where('created_at', '<', $before)
            ->limit(self::CHUNK)
            ->get(['id', 'academy_id', 'media_path']);

        if ($dryRun) {
            return $answers->count();
        }

        $removed = 0;

        foreach ($answers as $answer) {
            try {
                Storage::disk('tenant')->delete((string) $answer->media_path);
            } catch (Throwable) {
                // A missing object is the expected case once the bucket
                // lifecycle rule has already fired; the column still has to go.
            }

            Answer::query()
                ->withoutGlobalScope('academy')
                ->whereKey($answer->getKey())
                ->update(['media_path' => null]);

            $removed++;
        }

        return $removed;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, Academy>
     */
    private function academiesFor(array $ids): array
    {
        return Academy::query()
            ->withoutGlobalScopes()
            ->whereIn('id', array_map(intval(...), $ids))
            ->get()
            ->keyBy(static fn (Academy $academy): int => (int) $academy->getKey())
            ->all();
    }
}
