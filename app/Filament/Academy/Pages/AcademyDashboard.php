<?php

declare(strict_types=1);

namespace App\Filament\Academy\Pages;

use App\Filament\Academy\Widgets\AcademyOverviewWidget;
use App\Filament\Academy\Widgets\GradingQueueWidget;
use App\Filament\Academy\Widgets\ModulePerformanceWidget;
use Filament\Pages\Dashboard;

final class AcademyDashboard extends Dashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = -100;

    protected static string $routePath = '/';

    public function getTitle(): string
    {
        return __('panel.dashboard.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.dashboard.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reports.dashboard.view') === true;
    }

    /**
     * @return array<int, class-string>
     */
    public function getWidgets(): array
    {
        return [
            AcademyOverviewWidget::class,
            ModulePerformanceWidget::class,
            GradingQueueWidget::class,
        ];
    }

    /**
     * @return int|array<string, int|null>
     */
    public function getColumns(): int|array
    {
        return 2;
    }
}
