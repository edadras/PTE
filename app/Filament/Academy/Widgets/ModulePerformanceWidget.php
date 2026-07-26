<?php

declare(strict_types=1);

namespace App\Filament\Academy\Widgets;

use App\Domain\Identity\Models\StudentProgress;
use App\Domain\Learning\Enums\QuestionType;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * Average score per question type — where the academy is strong and where it
 * is not (docs/09 dashboard).
 */
final class ModulePerformanceWidget extends ChartWidget
{
    protected static ?int $sort = -90;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return auth()->user()?->can('reports.dashboard.view') === true;
    }

    public function getHeading(): string
    {
        return __('panel.stats.performance_by_type');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $rows = StudentProgress::query()
            ->select('question_type', DB::raw('AVG(avg_score) as score'))
            ->groupBy('question_type')
            ->orderBy('question_type')
            ->get();

        return [
            'datasets' => [[
                'label' => __('panel.students.field.avg_score'),
                'data' => $rows->map(static fn (StudentProgress $row): float => round((float) $row->score, 1))->all(),
            ]],
            'labels' => $rows->map(
                static fn (StudentProgress $row): string => QuestionType::tryFrom((string) $row->question_type)?->value
                    ?? (string) $row->question_type
            )->all(),
        ];
    }
}
