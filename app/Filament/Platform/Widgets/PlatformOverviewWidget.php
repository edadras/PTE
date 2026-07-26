<?php

declare(strict_types=1);

namespace App\Filament\Platform\Widgets;

use App\Domain\Identity\Enums\StudentStatus;
use App\Domain\Tenancy\Enums\AcademyStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Headline numbers for the platform: tenants, learners, and this month's AI
 * bill against the budget (docs/06 §7.4).
 */
final class PlatformOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -100;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $academies = DB::table('academies')->whereNull('deleted_at');

        $total = (int) (clone $academies)->count();
        $active = (int) (clone $academies)->where('status', AcademyStatus::Active->value)->count();
        $suspended = (int) (clone $academies)->where('status', AcademyStatus::Suspended->value)->count();

        $students = (int) DB::table('students')
            ->whereNull('deleted_at')
            ->where('status', StudentStatus::Active->value)
            ->count();

        $spend = self::spendThisMonthUsd();
        $budget = self::budgetUsd();
        $ratio = $budget > 0.0 ? min(100, (int) round($spend / $budget * 100)) : 0;

        return [
            Stat::make(__('panel.stats.academies'), (string) $total)
                ->description(__('panel.stats.academies_breakdown', ['active' => $active, 'suspended' => $suspended]))
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('primary'),

            Stat::make(__('panel.stats.active_students'), number_format($students))
                ->description(__('panel.stats.platform_wide'))
                ->descriptionIcon('heroicon-m-users')
                ->color('success'),

            Stat::make(__('panel.stats.ai_spend'), '$'.number_format($spend, 2))
                ->description(__('panel.stats.ai_budget', [
                    'budget' => '$'.number_format($budget, 0),
                    'percent' => $ratio,
                ]))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color(match (true) {
                    $ratio >= 95 => 'danger',
                    $ratio >= 80 => 'warning',
                    default => 'success',
                }),
        ];
    }

    public static function spendThisMonthUsd(): float
    {
        return (float) DB::table('ai_requests')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');
    }

    /** Configurable in config/pte.php (`ai.monthly_budget_usd`); see the report. */
    public static function budgetUsd(): float
    {
        return (float) config('pte.ai.monthly_budget_usd', 2500);
    }
}
