<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Jobs;

use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Assessment\Services\ReportCardBuilder;
use App\Domain\Shared\Jobs\TenantAwareJob;
use Illuminate\Support\Facades\Cache;

/**
 * Pre-builds a report card so the bot can answer "show my result" instantly.
 *
 * The payload is cached rather than stored: it is entirely derivable from
 * answers and scores, and caching it keeps a stale copy from outliving a
 * teacher's override for longer than the TTL.
 */
final class BuildReportCard extends TenantAwareJob
{
    public int $tries = 3;

    public function __construct(
        int $academyId,
        public readonly SessionType $sessionType,
        public readonly int $sessionId,
    ) {
        parent::__construct($academyId);
    }

    public function handle(ReportCardBuilder $builder): void
    {
        $session = $this->sessionType === SessionType::Exam
            ? ExamSession::query()->find($this->sessionId)
            : PracticeSession::query()->find($this->sessionId);

        if ($session === null) {
            return;
        }

        // Cache::* is already tenant-prefixed by TenantContext.
        Cache::put(
            self::cacheKey($this->sessionType, $this->sessionId),
            $builder->build($session),
            now()->addDays((int) config('pte.retention.reports', 7))
        );
    }

    public static function cacheKey(SessionType $type, int $sessionId): string
    {
        return "report-card:{$type->value}:{$sessionId}";
    }
}
