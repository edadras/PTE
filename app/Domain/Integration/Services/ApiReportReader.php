<?php

declare(strict_types=1);

namespace App\Domain\Integration\Services;

use App\Domain\AI\Models\AiRequest;
use App\Domain\Assessment\Models\Score;
use App\Domain\Commerce\Data\QuotaResult;
use App\Domain\Commerce\Services\QuotaGuard;
use App\Domain\Identity\Enums\StudentStatus;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Models\Question;
use App\Domain\Reporting\Enums\StatMetric;
use App\Domain\Reporting\Services\DashboardStats;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Read models for the reporting endpoints of docs/08 §3.
 *
 * Period counters delegate to Reporting's DashboardStats — daily_stats rolled
 * up by StatMetric aggregation, with only "today" computed live — so the panel
 * and the API read the same numbers from the same code (CONVENTIONS §6).
 *
 * What remains inline is what Reporting does not yet own, kept as a thin
 * adapter on purpose:
 *  - `students.active` — a *status* count; StatMetric::StudentsActive means
 *    "answered today", a different fact;
 *  - `content.questions` — no content-size metric exists;
 *  - `activity.scores` / `average_percentage` — Score-based, where the
 *    collector's AverageScore is Answer-based;
 *  - the whole of aiUsage() — token/cache-hit/failure breakdowns are not
 *    collected, and mixing daily_stats counts with live token sums would
 *    produce a self-contradictory payload.
 * Each should move to a Reporting collector method, then delete here.
 */
final class ApiReportReader
{
    public function __construct(private readonly DashboardStats $stats) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(?Carbon $since = null): array
    {
        $since ??= now()->subDays(30);

        $academy = TenantContext::require();
        $from = $since->copy();
        $to = $this->stats->today($academy);

        return [
            'students' => [
                'total' => (int) $this->stats->summary(StatMetric::StudentsTotal, $from, $to, $academy),
                'active' => Student::query()->where('status', StudentStatus::Active->value)->count(),
                'new_in_period' => (int) $this->stats->summary(StatMetric::StudentsNew, $from, $to, $academy),
            ],
            'content' => [
                'questions' => Question::query()->count(),
            ],
            'activity' => [
                'practice_sessions' => (int) $this->stats->summary(StatMetric::PracticeSessions, $from, $to, $academy),
                'exam_sessions' => (int) $this->stats->summary(StatMetric::ExamSessions, $from, $to, $academy),
                'scores' => Score::query()->where('created_at', '>=', $since)->count(),
                'average_percentage' => round(
                    (float) Score::query()->where('created_at', '>=', $since)->avg('percentage'),
                    2
                ),
            ],
            'period' => [
                'from' => $since->toIso8601String(),
                'to' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function aiUsage(?string $period = null): array
    {
        $period ??= now()->format('Y-m');
        [$year, $month] = array_map('intval', explode('-', $period) + [1 => 1]);

        $from = Carbon::create($year, max(1, $month), 1, 0, 0, 0) ?? now()->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $requests = AiRequest::query()->whereBetween('created_at', [$from, $to]);

        return [
            'period' => $period,
            'requests' => (clone $requests)->count(),
            'failed' => (clone $requests)->where('status', 'failed')->count(),
            'cache_hits' => (clone $requests)->where('cache_hit', true)->count(),
            'prompt_tokens' => (int) (clone $requests)->sum('prompt_tokens'),
            'completion_tokens' => (int) (clone $requests)->sum('completion_tokens'),
            'total_tokens' => (int) (clone $requests)->sum('total_tokens'),
            'audio_minutes' => round((float) (clone $requests)->sum('audio_minutes'), 2),
            'cost_usd' => round((float) (clone $requests)->sum('cost_usd'), 4),
            'quota' => $this->quotaSnapshot(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function quotaSnapshot(): array
    {
        // Quota counters live outside the tenant scope machinery, so the guard
        // is asked for the academy that is actually active right now.
        $guard = QuotaGuard::forAcademy(TenantContext::id());

        return array_map(
            static fn (QuotaResult $result): array => $result->toArray(),
            $guard->snapshot(),
        );
    }
}
