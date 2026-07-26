<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Domain\AI\Models\AiRequest;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/platform/v1/ai/cost` — where the money went, per academy and per
 * model. The single most important number on the platform dashboard: AI spend
 * is the one cost that scales with usage rather than with revenue (docs/09).
 */
final class AiCostController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('platform.billing.manage');

        $request->validate(['period' => ['nullable', 'regex:/^\d{4}-\d{2}$/']]);

        $period = $request->string('period')->toString() ?: now()->format('Y-m');
        [$year, $month] = array_map('intval', explode('-', $period));

        $from = Carbon::create($year, $month, 1, 0, 0, 0) ?? now()->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $base = AiRequest::query()->withoutTenantScope()->whereBetween('created_at', [$from, $to]);

        $byAcademy = (clone $base)
            ->selectRaw('academy_id, count(*) as requests, sum(total_tokens) as tokens, sum(cost_usd) as cost_usd')
            ->groupBy('academy_id')
            ->orderByDesc('cost_usd')
            ->get()
            ->map(static fn (object $row): array => [
                'academy_id' => (int) $row->academy_id,
                'requests' => (int) $row->requests,
                'tokens' => (int) $row->tokens,
                'cost_usd' => round((float) $row->cost_usd, 4),
            ])
            ->all();

        $byModel = (clone $base)
            ->selectRaw('model_key, provider, count(*) as requests, sum(cost_usd) as cost_usd')
            ->groupBy('model_key', 'provider')
            ->orderByDesc('cost_usd')
            ->get()
            ->map(static fn (object $row): array => [
                'model' => (string) $row->model_key,
                'provider' => (string) $row->provider,
                'requests' => (int) $row->requests,
                'cost_usd' => round((float) $row->cost_usd, 4),
            ])
            ->all();

        return $this->payload($request, [
            'period' => $period,
            'total_requests' => (clone $base)->count(),
            'total_cost_usd' => round((float) (clone $base)->sum('cost_usd'), 4),
            'total_tokens' => (int) (clone $base)->sum('total_tokens'),
            'by_academy' => $byAcademy,
            'by_model' => $byModel,
        ]);
    }
}
