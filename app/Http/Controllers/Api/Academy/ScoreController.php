<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Assessment\Models\Score;
use App\Domain\Reporting\Actions\ExportReport;
use App\Domain\Reporting\Enums\ReportType;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\ScoreResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/api/v1/scores?from=&to=&module=` — docs/08 §3.
 *
 * `?format=xlsx` queues a scores report (202 + id, poll `/exports/{id}`)
 * instead of paginating inline.
 */
final class ScoreController extends ApiController
{
    public function index(Request $request, ExportReport $export): ApiCollection|JsonResponse
    {
        $request->validate(['format' => ['nullable', 'in:json,xlsx']]);

        if ($request->string('format')->toString() === 'xlsx') {
            return $this->queueExport($request, $export);
        }

        $scores = Score::query()
            ->when(
                $request->filled('module'),
                fn (Builder $query): Builder => $query->where('module_key', (string) $request->string('module'))
            )
            ->when(
                $request->filled('from'),
                fn (Builder $query): Builder => $query->where('created_at', '>=', $request->date('from'))
            )
            ->when(
                $request->filled('to'),
                fn (Builder $query): Builder => $query->where('created_at', '<=', $request->date('to'))
            )
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return new ApiCollection($scores, ScoreResource::class);
    }

    private function queueExport(Request $request, ExportReport $export): JsonResponse
    {
        $params = array_filter([
            'from' => $request->filled('from') ? $request->string('from')->toString() : null,
            'to' => $request->filled('to') ? $request->string('to')->toString() : null,
        ], static fn (?string $value): bool => $value !== null);

        $report = $export->queue(ReportType::Scores, $params, format: 'xlsx');

        return $this->payload($request, [
            'report_id' => (int) $report->getKey(),
            'status' => $report->status->value,
            'format' => 'xlsx',
        ], status: 202);
    }
}
