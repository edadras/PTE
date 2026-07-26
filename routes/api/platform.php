<?php

declare(strict_types=1);

use App\Domain\Integration\Support\ApiBootstrap;
use App\Http\Controllers\Api\Platform\AcademyController;
use App\Http\Controllers\Api\Platform\AiCostController;
use App\Http\Controllers\Api\Platform\AnalyticsController;
use App\Http\Controllers\Api\Platform\BotHealthController;
use App\Http\Controllers\Api\Platform\PlanController;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform API — /api/platform/v1
|--------------------------------------------------------------------------
|
| Super admin only, and deliberately tenant-less: every controller here reaches
| across academies and each one re-checks its `platform.*` gate itself.
|
| @see docs/08-api-and-integrations.md §1
*/

ApiBootstrap::register();

Route::prefix('platform/v1')
    ->name('api.platform.')
    ->middleware([ForceJsonResponse::class, 'auth:sanctum', 'throttle:api'])
    ->group(function (): void {
        Route::get('academies', [AcademyController::class, 'index'])->name('academies.index');
        Route::post('academies', [AcademyController::class, 'store'])->name('academies.store');
        Route::get('academies/statuses', [AcademyController::class, 'statuses'])->name('academies.statuses');
        Route::get('academies/{academy}', [AcademyController::class, 'show'])->name('academies.show');
        Route::patch('academies/{academy}', [AcademyController::class, 'update'])->name('academies.update');
        Route::delete('academies/{academy}', [AcademyController::class, 'destroy'])->name('academies.destroy');
        Route::post('academies/{academy}/suspension', [AcademyController::class, 'suspend'])
            ->name('academies.suspension');

        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
        Route::get('plans/{plan}', [PlanController::class, 'show'])->name('plans.show');

        Route::get('analytics', AnalyticsController::class)->name('analytics');
        Route::get('ai/cost', AiCostController::class)->name('ai.cost');
        Route::get('bots/health', BotHealthController::class)->name('bots.health');
    });
