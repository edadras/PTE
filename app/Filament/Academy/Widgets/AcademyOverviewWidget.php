<?php

declare(strict_types=1);

namespace App\Filament\Academy\Widgets;

use App\Domain\AI\Models\AiRequest;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Support\Money;
use App\Domain\Identity\Enums\StudentStatus;
use App\Domain\Identity\Models\Student;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The academy dashboard headline of docs/09: learners, today's activity, AI
 * usage, average score and revenue. Every query is tenant-scoped by the model.
 */
final class AcademyOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -100;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('reports.dashboard.view') === true;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $today = now()->startOfDay();

        $students = Student::query()->where('status', StudentStatus::Active->value)->count();
        $activeToday = Student::query()->where('last_active_at', '>=', $today)->count();

        $practices = PracticeSession::query()->where('created_at', '>=', $today)->count();
        $exams = ExamSession::query()->where('created_at', '>=', $today)->count();

        $aiRequests = AiRequest::query()->where('created_at', '>=', $today)->count();

        $averageScore = (float) Answer::query()
            ->whereNotNull('score')
            ->where('created_at', '>=', now()->subDays(30))
            ->avg('score');

        $stats = [
            Stat::make(__('panel.stats.students'), number_format($students))
                ->description(__('panel.stats.active_today', ['count' => $activeToday]))
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make(__('panel.stats.today_practice'), number_format($practices))
                ->description(__('panel.stats.today_exams', ['count' => $exams]))
                ->descriptionIcon('heroicon-m-pencil-square')
                ->color('success'),

            Stat::make(__('panel.stats.ai_requests_today'), number_format($aiRequests))
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color('info'),

            Stat::make(__('panel.stats.average_score'), number_format($averageScore, 1))
                ->description(__('panel.stats.last_30_days'))
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('warning'),
        ];

        if (auth()->user()?->can('reports.financial.view') === true) {
            $paid = (int) Payment::query()
                ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value])
                ->where('paid_at', '>=', now()->startOfMonth())
                ->sum('amount');

            $currency = (string) (Payment::query()->orderByDesc('id')->value('currency') ?? 'IRR');

            $stats[] = Stat::make(__('panel.stats.revenue'), (new Money($paid, $currency))->format())
                ->description(__('panel.stats.this_month'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success');
        }

        return $stats;
    }

    /** Kept for readability where session type matters in future breakdowns. */
    public static function examSessionType(): SessionType
    {
        return SessionType::Exam;
    }
}
