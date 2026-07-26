<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\Enums\AcademyStatus;
use App\Domain\Tenancy\Models\Academy;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/platform/v1/analytics` — the cross-tenant roll-up of docs/08 §1.
 *
 * `withoutTenantScope()` is the only sanctioned way out of the tenant boundary
 * and it re-checks `platform.view_all` itself, so this is a double gate on
 * purpose (CONVENTIONS §3.4).
 */
final class AnalyticsController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('platform.analytics.view');

        $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']]);

        $since = now()->subDays((int) $request->integer('days', 30));

        $byStatus = [];

        foreach (AcademyStatus::cases() as $status) {
            $byStatus[$status->value] = Academy::query()->where('status', $status->value)->count();
        }

        return $this->payload($request, [
            'academies' => [
                'total' => Academy::query()->count(),
                'by_status' => $byStatus,
                'new_in_period' => Academy::query()->where('created_at', '>=', $since)->count(),
            ],
            'students' => [
                'total' => Student::query()->withoutTenantScope()->count(),
                'new_in_period' => Student::query()
                    ->withoutTenantScope()
                    ->where('created_at', '>=', $since)
                    ->count(),
            ],
            'activity' => [
                'practice_sessions' => PracticeSession::query()
                    ->withoutTenantScope()
                    ->where('created_at', '>=', $since)
                    ->count(),
                'exam_sessions' => ExamSession::query()
                    ->withoutTenantScope()
                    ->where('created_at', '>=', $since)
                    ->count(),
            ],
            'period' => [
                'from' => $since->toIso8601String(),
                'to' => now()->toIso8601String(),
            ],
        ]);
    }
}
