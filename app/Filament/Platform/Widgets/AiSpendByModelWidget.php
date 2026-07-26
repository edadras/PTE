<?php

declare(strict_types=1);

namespace App\Filament\Platform\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * "By model" half of the cost dashboard in docs/06 §7.4.
 */
final class AiSpendByModelWidget extends Widget
{
    protected static string $view = 'filament.platform.widgets.spend-table';

    protected static ?int $sort = -80;

    protected int|string|array $columnSpan = 1;

    public function getHeading(): string
    {
        return __('panel.stats.spend_by_model');
    }

    /**
     * @return array<int, array{label: string, amount: float, share: float}>
     */
    public function getRows(): array
    {
        $rows = DB::table('ai_requests')
            ->leftJoin('ai_models', 'ai_models.model_key', '=', 'ai_requests.model_key')
            ->where('ai_requests.created_at', '>=', now()->startOfMonth())
            ->groupBy('ai_requests.model_key', 'ai_models.display_name')
            ->orderByRaw('SUM(ai_requests.cost_usd) DESC')
            ->get([
                'ai_requests.model_key as model_key',
                'ai_models.display_name as display_name',
                DB::raw('SUM(ai_requests.cost_usd) as amount'),
            ]);

        $total = (float) $rows->sum(static fn (object $row): float => (float) $row->amount);

        return $rows
            ->map(static fn (object $row): array => [
                'label' => (string) ($row->display_name ?? $row->model_key),
                'amount' => (float) $row->amount,
                'share' => $total > 0.0 ? (float) $row->amount / $total : 0.0,
            ])
            ->values()
            ->all();
    }
}
