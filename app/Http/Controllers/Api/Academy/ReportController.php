<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Integration\Services\ApiReportReader;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/api/v1/reports/*` and `/api/v1/ai/usage` — docs/08 §3.
 */
final class ReportController extends ApiController
{
    public function __construct(private readonly ApiReportReader $reader) {}

    public function dashboard(Request $request): JsonResponse
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']]);

        return $this->payload(
            $request,
            $this->reader->dashboard(now()->subDays((int) $request->integer('days', 30))),
        );
    }

    public function aiUsage(Request $request): JsonResponse
    {
        $request->validate(['period' => ['nullable', 'regex:/^\d{4}-\d{2}$/']]);

        return $this->payload(
            $request,
            $this->reader->aiUsage($request->string('period')->toString() ?: null),
        );
    }
}
