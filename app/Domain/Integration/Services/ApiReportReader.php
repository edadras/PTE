<?php

declare(strict_types=1);

namespace App\Domain\Integration\Services;

use App\Domain\AI\Models\AiRequest;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Assessment\Models\Score;
use App\Domain\Commerce\Data\QuotaResult;
use App\Domain\Commerce\Services\QuotaGuard;
use App\Domain\Identity\Enums\StudentStatus;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Models\Question;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Read models for the reporting endpoints of docs/08 §3.
 *
 * This lives in a service rather than a controller for the reason CONVENTIONS
 * §6 gives: the panel and the API must show the same numbers, and the only way
 * to guarantee that is for them to run the same code. It is a stopgap: the
 * Reporting context owns this kind of read model, and these two methods should
 * move onto its collectors once their shape has settled.
 */
final class ApiReportReader
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(?Carbon $since = null): array
    {
        $since ??= now()->subDays(30);

        return [
            'students' => [
                'total' => Student::query()->count(),
                'active' => Student::query()->where('status', StudentStatus::Active->value)->count(),
                'new_in_period' => Student::query()->where('created_at', '>=', $since)->count(),
            ],
            'content' => [
                'questions' => Question::query()->count(),
            ],
            'activity' => [
                'practice_sessions' => PracticeSession::query()->where('created_at', '>=', $since)->count(),
                'exam_sessions' => ExamSession::query()->where('created_at', '>=', $since)->count(),
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
