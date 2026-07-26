<?php

declare(strict_types=1);

namespace App\Filament\Platform\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * "By academy" half of the cost dashboard in docs/06 §7.4.
 *
 * Reads `ai_requests` directly rather than through the tenant-scoped model:
 * this is a cross-academy report and there is no Reporting service yet.
 */
final class AiSpendByAcademyWidget extends Widget
{
    protected static string $view = 'filament.platform.widgets.spend-table';

    protected static ?int $sort = -90;

    protected int|string|array $columnSpan = 1;

    private const TOP_N = 8;

    public function getHeading(): string
    {
        return __('panel.stats.spend_by_academy');
    }

    /**
     * @return array<int, array{label: string, amount: float, share: float}>
     */
    public function getRows(): array
    {
        $rows = DB::table('ai_requests')
            ->join('academies', 'academies.id', '=', 'ai_requests.academy_id')
            ->where('ai_requests.created_at', '>=', now()->startOfMonth())
            ->groupBy('academies.id', 'academies.name')
            ->orderByRaw('SUM(ai_requests.cost_usd) DESC')
            ->get([
                'academies.name as name',
                DB::raw('SUM(ai_requests.cost_usd) as amount'),
            ]);

        $total = (float) $rows->sum(static fn (object $row): float => (float) $row->amount);

        $top = $rows->take(self::TOP_N)
            ->map(static fn (object $row): array => [
                'label' => (string) $row->name,
                'amount' => (float) $row->amount,
                'share' => $total > 0.0 ? (float) $row->amount / $total : 0.0,
            ])
            ->values()
            ->all();

        $rest = $rows->skip(self::TOP_N);

        if ($rest->isNotEmpty()) {
            $restAmount = (float) $rest->sum(static fn (object $row): float => (float) $row->amount);

            $top[] = [
                'label' => __('panel.stats.others', ['count' => $rest->count()]),
                'amount' => $restAmount,
                'share' => $total > 0.0 ? $restAmount / $total : 0.0,
            ];
        }

        return $top;
    }
}
