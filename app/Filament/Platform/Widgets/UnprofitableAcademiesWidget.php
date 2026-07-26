<?php

declare(strict_types=1);

namespace App\Filament\Platform\Widgets;

use App\Domain\Commerce\Data\ProfitabilitySnapshot;
use App\Domain\Commerce\Services\ProfitabilityAnalyzer;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * The alarm from docs/06 §7.4: which customer is costing more than it pays,
 * shown before the vendor invoice arrives rather than after.
 */
final class UnprofitableAcademiesWidget extends Widget
{
    protected static string $view = 'filament.platform.widgets.unprofitable';

    protected static ?int $sort = -70;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<int, array{name: string, ai_cost: float, revenue: float, ratio: ?float}>
     */
    public function getRows(): array
    {
        /** @var array<int, ProfitabilitySnapshot> $snapshots */
        $snapshots = app(ProfitabilityAnalyzer::class)->unprofitable()->all();

        if ($snapshots === []) {
            return [];
        }

        $names = DB::table('academies')
            ->whereIn('id', array_map(
                static fn (ProfitabilitySnapshot $s): int => $s->academyId,
                $snapshots,
            ))
            ->pluck('name', 'id');

        return array_map(
            static fn (ProfitabilitySnapshot $s): array => [
                'name' => (string) ($names[$s->academyId] ?? ('#'.$s->academyId)),
                'ai_cost' => $s->aiCostUsdCents / 100,
                'revenue' => $s->revenueUsdCents / 100,
                'ratio' => $s->ratio(),
            ],
            $snapshots,
        );
    }
}
