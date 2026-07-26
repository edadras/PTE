<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Filament\Platform\Widgets\AiSpendByAcademyWidget;
use App\Filament\Platform\Widgets\AiSpendByModelWidget;
use App\Filament\Platform\Widgets\BotHealthWidget;
use App\Filament\Platform\Widgets\PlatformOverviewWidget;
use App\Filament\Platform\Widgets\UnprofitableAcademiesWidget;
use Filament\Pages\Dashboard;

final class PlatformDashboard extends Dashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = -100;

    protected static string $routePath = '/';

    public function getTitle(): string
    {
        return __('panel.dashboard.platform_title');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.dashboard.title');
    }

    /**
     * @return array<int, class-string>
     */
    public function getWidgets(): array
    {
        return [
            PlatformOverviewWidget::class,
            AiSpendByAcademyWidget::class,
            AiSpendByModelWidget::class,
            UnprofitableAcademiesWidget::class,
            BotHealthWidget::class,
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
