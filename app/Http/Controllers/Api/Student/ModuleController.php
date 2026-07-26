<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Student;

use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Support\ModuleRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/student/v1/modules` — what this academy has switched on.
 */
final class ModuleController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $academy = TenantContext::require();

        $modules = array_map(
            static fn (ModuleKey $module): array => [
                'key' => $module->value,
                'label' => $module->label(),
                'icon' => $module->icon(),
                'question_types' => array_map(
                    static fn (QuestionType $type): array => [
                        'key' => $type->value,
                        'label' => $type->label(),
                    ],
                    $module->questionTypes(),
                ),
            ],
            ModuleRegistry::enabledFor($academy),
        );

        return $this->payload($request, ['modules' => array_values($modules)]);
    }
}
