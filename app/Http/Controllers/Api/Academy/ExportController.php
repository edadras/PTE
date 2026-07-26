<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Reporting\Enums\ReportStatus;
use App\Domain\Reporting\Models\Report;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/exports/{id}` — poll a queued export until it is downloadable.
 *
 * The download link is the short-lived pre-signed URL from the Report model
 * (docs/10 §4): the file itself is private and is never streamed through the
 * API process.
 */
final class ExportController extends ApiController
{
    public function __invoke(Request $request, int $report): JsonResponse
    {
        $model = Report::query()->findOrFail($report);

        return $this->payload($request, [
            'report_id' => (int) $model->getKey(),
            'type' => $model->type->value,
            'format' => $model->format,
            'status' => $model->status->value,
            'row_count' => $model->row_count,
            'generated_at' => $model->generated_at?->toIso8601String(),
            'expires_at' => $model->expires_at?->toIso8601String(),
            'download_url' => $model->temporaryUrl(),
            'error' => $model->status === ReportStatus::Failed ? $model->getAttribute('error') : null,
        ]);
    }
}
