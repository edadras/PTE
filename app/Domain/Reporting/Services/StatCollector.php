<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\AI\Models\AiRequest;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Identity\Models\Student;
use App\Domain\Reporting\Enums\StatMetric;
use App\Domain\Telegram\Models\TelegramMessage;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Support\Carbon;

/**
 * Computes one day's metrics from the live tables.
 *
 * Exists so the nightly aggregation and the dashboard's "today" column cannot
 * disagree: both ask this class, one persists the answer and the other does not.
 *
 * A "day" is the academy's calendar day, converted to a UTC range for the query
 * — timestamps are stored in UTC (docs/07 §1) but an academy in Tehran does not
 * consider its evening practice to belong to tomorrow.
 *
 * Must be called inside the tenant: every model here is tenant-scoped and will
 * refuse to answer otherwise.
 */
final class StatCollector
{
    /**
     * @return array<string, float>
     */
    public function collect(Academy $academy, Carbon $date): array
    {
        [$from, $to] = $this->window($academy, $date);

        $answers = $this->answerTotals($from, $to);

        return [
            StatMetric::StudentsTotal->value => (float) Student::query()->count(),
            StatMetric::StudentsNew->value => (float) Student::query()
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            StatMetric::StudentsActive->value => (float) Answer::query()
                ->whereBetween('created_at', [$from, $to])
                ->distinct()
                ->count('student_id'),
            StatMetric::PracticeSessions->value => (float) PracticeSession::query()
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            StatMetric::ExamSessions->value => (float) ExamSession::query()
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            StatMetric::AnswersSubmitted->value => (float) $answers['count'],
            StatMetric::AiRequests->value => (float) AiRequest::query()
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            StatMetric::AiCostUsd->value => round((float) AiRequest::query()
                ->whereBetween('created_at', [$from, $to])
                ->sum('cost_usd'), 4),
            StatMetric::AverageScore->value => $answers['average'],
            StatMetric::Revenue->value => (float) Payment::query()
                ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value])
                ->whereBetween('paid_at', [$from, $to])
                ->sum('amount'),
            StatMetric::TelegramMessages->value => (float) TelegramMessage::query()
                ->whereBetween('created_at', [$from, $to])
                ->count(),
        ];
    }

    /**
     * The UTC instants bounding one academy-local calendar day.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function window(Academy $academy, Carbon $date): array
    {
        $timezone = $this->timezoneFor($academy);

        $start = $date->copy()->setTimezone($timezone)->startOfDay();

        return [
            $start->copy()->utc(),
            $start->copy()->endOfDay()->utc(),
        ];
    }

    public function timezoneFor(Academy $academy): string
    {
        $timezone = $academy->getAttribute('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC');
    }

    /**
     * Percentage as sum(score)/sum(max) rather than the mean of per-answer
     * percentages: a one-mark question would otherwise weigh as much as a
     * ninety-mark essay.
     *
     * @return array{count: int, average: float}
     */
    private function answerTotals(Carbon $from, Carbon $to): array
    {
        $row = Answer::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as answer_count, COALESCE(SUM(score), 0) as score_sum, COALESCE(SUM(max_score), 0) as max_sum')
            ->first();

        $count = (int) ($row?->getAttribute('answer_count') ?? 0);
        $scoreSum = (float) ($row?->getAttribute('score_sum') ?? 0);
        $maxSum = (float) ($row?->getAttribute('max_sum') ?? 0);

        return [
            'count' => $count,
            'average' => $maxSum > 0.0 ? round($scoreSum / $maxSum * 100, 2) : 0.0,
        ];
    }
}
