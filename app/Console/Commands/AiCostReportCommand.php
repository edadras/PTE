<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAcademy;
use App\Domain\AI\Models\AiRequest;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * AI spend for a month, broken down per academy.
 *
 * Reads `ai_requests`, which is the billing truth — every provider call lands
 * there including the failures and the cache hits, so a total assembled from
 * anywhere else is guaranteed to be lower than the invoice (docs/06 §7.1).
 *
 * Cache hits and fallbacks are shown alongside the money because they are the
 * two levers that move it.
 *
 * @see docs/10-infrastructure-and-ops.md §8
 */
final class AiCostReportCommand extends Command
{
    use ResolvesAcademy;

    protected $signature = 'ai:cost-report
        {--period= : Month as YYYY-MM (defaults to the current month)}
        {--academy= : Restrict to one academy (id or slug)}
        {--by-task : Break down by task key instead of by academy}
        {--json}';

    protected $description = 'Report AI spend, tokens and cache efficiency for a period.';

    public function handle(): int
    {
        $period = $this->period();

        if ($period === null) {
            $this->components->error(__('reports.console.bad_period'));

            return self::INVALID;
        }

        [$from, $to] = $period;

        $academyId = null;

        if (is_string($this->option('academy')) && $this->option('academy') !== '') {
            $academy = $this->requireAcademy((string) $this->option('academy'));

            if ($academy === null) {
                return self::FAILURE;
            }

            $academyId = (int) $academy->getKey();
        }

        $groupColumn = $this->option('by-task') === true ? 'task_key' : 'academy_id';

        $rows = AiRequest::query()
            ->withoutGlobalScope('academy')
            ->when($academyId !== null, fn ($q) => $q->where('academy_id', $academyId))
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw($groupColumn.' as bucket')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as tokens')
            ->selectRaw('COALESCE(SUM(cost_usd), 0) as cost_usd')
            ->selectRaw('COALESCE(SUM(audio_minutes), 0) as audio_minutes')
            ->selectRaw('SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) as cache_hits')
            ->selectRaw('SUM(CASE WHEN fallback_used = 1 THEN 1 ELSE 0 END) as fallbacks')
            ->groupBy('bucket')
            ->orderByDesc('cost_usd')
            ->get();

        $labels = $groupColumn === 'academy_id' ? $this->academyLabels($rows->pluck('bucket')->all()) : [];

        $report = $rows->map(static function (AiRequest $row) use ($labels, $groupColumn): array {
            $requests = (int) $row->getAttribute('requests');
            $bucket = $row->getAttribute('bucket');

            return [
                'bucket' => $groupColumn === 'academy_id'
                    ? ($labels[(int) $bucket] ?? (string) $bucket)
                    : (string) $bucket,
                'requests' => $requests,
                'tokens' => (int) $row->getAttribute('tokens'),
                'audio_minutes' => round((float) $row->getAttribute('audio_minutes'), 2),
                'cost_usd' => round((float) $row->getAttribute('cost_usd'), 4),
                'cache_hit_rate' => $requests > 0
                    ? round((int) $row->getAttribute('cache_hits') / $requests * 100, 1)
                    : 0.0,
                'fallback_rate' => $requests > 0
                    ? round((int) $row->getAttribute('fallbacks') / $requests * 100, 1)
                    : 0.0,
            ];
        })->all();

        $totalCost = round(array_sum(array_column($report, 'cost_usd')), 4);

        if ($this->option('json') === true) {
            $this->line((string) json_encode([
                'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
                'total_cost_usd' => $totalCost,
                'rows' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info(__('reports.console.cost_period', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]));

        if ($report === []) {
            $this->components->warn(__('reports.console.no_ai_usage'));

            return self::SUCCESS;
        }

        $this->table(
            [$groupColumn === 'academy_id' ? 'Academy' : 'Task', 'Requests', 'Tokens', 'Audio min', 'Cost USD', 'Cache %', 'Fallback %'],
            array_map(array_values(...), $report),
        );

        $this->components->twoColumnDetail(
            __('reports.console.total_cost'),
            '$'.number_format($totalCost, 4)
        );

        // The IRR figure is indicative: `usd_to_irr` is a static placeholder
        // until a real FX feed exists (config/pte.php).
        $rate = (int) config('pte.commerce.usd_to_irr', 0);

        if ($rate > 0) {
            $this->components->twoColumnDetail(
                __('reports.console.total_cost_local'),
                number_format($totalCost * $rate).' IRR'
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function period(): ?array
    {
        $period = $this->option('period');

        if (! is_string($period) || $period === '') {
            return [now()->startOfMonth(), now()->endOfMonth()];
        }

        if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            return null;
        }

        $start = Carbon::createFromFormat('Y-m-d H:i:s', $period.'-01 00:00:00');

        return $start === false ? null : [$start->startOfMonth(), $start->copy()->endOfMonth()];
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function academyLabels(array $ids): array
    {
        return Academy::query()
            ->withoutGlobalScopes()
            ->whereIn('id', array_map(intval(...), $ids))
            ->get(['id', 'slug'])
            ->mapWithKeys(static fn (Academy $a): array => [(int) $a->getKey() => (string) $a->slug])
            ->all();
    }
}
