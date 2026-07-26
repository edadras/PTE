<?php

declare(strict_types=1);

use App\Domain\Integration\Support\ApiBootstrap;
use App\Http\Controllers\Api\Academy\CourseController;
use App\Http\Controllers\Api\Academy\ExamController;
use App\Http\Controllers\Api\Academy\ExamResultController;
use App\Http\Controllers\Api\Academy\QuestionBankController;
use App\Http\Controllers\Api\Academy\QuestionController;
use App\Http\Controllers\Api\Academy\QuestionImportController;
use App\Http\Controllers\Api\Academy\ReportController;
use App\Http\Controllers\Api\Academy\ScoreController;
use App\Http\Controllers\Api\Academy\StudentController;
use App\Http\Controllers\Api\Academy\StudentImportController;
use App\Http\Controllers\Api\Academy\StudentProgressController;
use App\Http\Controllers\Api\Academy\StudentScoreController;
use App\Http\Controllers\Api\Academy\TelegramController;
use App\Http\Controllers\Api\Academy\WebhookController;
use App\Http\Middleware\ApiKeyScope;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\IdempotencyKey;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Academy API — /api/v1
|--------------------------------------------------------------------------
|
| Tenant comes from the API key (`tenant.api`); authorisation comes from that
| key's scopes (`api.scope`). Every model touched below is tenant-scoped, so a
| key from academy A resolving an id from academy B gets a 404, never a 403.
|
| @see docs/08-api-and-integrations.md §3
*/

ApiBootstrap::register();

Route::prefix('v1')
    ->name('api.academy.')
    ->middleware([ForceJsonResponse::class, 'tenant.api', 'throttle:api'])
    ->group(function (): void {
        /*
        | Students
        */
        Route::middleware(ApiKeyScope::class.':students:read,students:write')->group(function (): void {
            Route::get('students', [StudentController::class, 'index'])->name('students.index');
            Route::get('students/{student}', [StudentController::class, 'show'])->name('students.show');
            Route::get('students/{student}/progress', StudentProgressController::class)
                ->name('students.progress');
            Route::get('students/{student}/scores', StudentScoreController::class)
                ->name('students.scores');
        });

        Route::middleware(ApiKeyScope::class.':students:write')->group(function (): void {
            Route::post('students', [StudentController::class, 'store'])
                ->middleware(IdempotencyKey::class)
                ->name('students.store');
            Route::post('students/import', StudentImportController::class)
                ->middleware(IdempotencyKey::class)
                ->name('students.import');
            Route::patch('students/{student}', [StudentController::class, 'update'])->name('students.update');
            Route::delete('students/{student}', [StudentController::class, 'destroy'])->name('students.destroy');
        });

        /*
        | Content
        */
        Route::get('courses', [CourseController::class, 'index'])
            ->middleware(ApiKeyScope::class.':questions:write,reports:read')
            ->name('courses.index');
        Route::post('courses', [CourseController::class, 'store'])
            ->middleware(ApiKeyScope::class.':questions:write')
            ->name('courses.store');

        Route::get('question-banks', [QuestionBankController::class, 'index'])
            ->middleware(ApiKeyScope::class.':questions:write,exams:read')
            ->name('question-banks.index');

        Route::get('questions', [QuestionController::class, 'index'])
            ->middleware(ApiKeyScope::class.':questions:write,exams:read')
            ->name('questions.index');
        Route::post('questions', [QuestionController::class, 'store'])
            ->middleware([ApiKeyScope::class.':questions:write', IdempotencyKey::class])
            ->name('questions.store');
        Route::post('questions/bulk-import', QuestionImportController::class)
            ->middleware([ApiKeyScope::class.':questions:write', IdempotencyKey::class])
            ->name('questions.bulk-import');

        /*
        | Assessment
        */
        Route::middleware(ApiKeyScope::class.':exams:read')->group(function (): void {
            Route::get('exams', [ExamController::class, 'index'])->name('exams.index');
            Route::get('exams/{exam}/sessions', [ExamController::class, 'sessions'])->name('exams.sessions');
            Route::get('exams/{exam}/results', ExamResultController::class)->name('exams.results');
        });

        Route::middleware(ApiKeyScope::class.':questions:write')->group(function (): void {
            Route::post('exams', [ExamController::class, 'store'])
                ->middleware(IdempotencyKey::class)
                ->name('exams.store');
            Route::post('exams/{exam}/publish', [ExamController::class, 'publish'])->name('exams.publish');
        });

        Route::get('scores', [ScoreController::class, 'index'])
            ->middleware(ApiKeyScope::class.':scores:read')
            ->name('scores.index');

        /*
        | Reporting
        */
        Route::middleware(ApiKeyScope::class.':reports:read')->group(function (): void {
            Route::get('reports/dashboard', [ReportController::class, 'dashboard'])->name('reports.dashboard');
            Route::get('reports/ai-usage', [ReportController::class, 'aiUsage'])->name('reports.ai-usage');
            Route::get('ai/usage', [ReportController::class, 'aiUsage'])->name('ai.usage');
        });

        /*
        | Telegram
        */
        Route::middleware(ApiKeyScope::class.':telegram:send')->group(function (): void {
            Route::post('telegram/send', [TelegramController::class, 'send'])
                ->middleware(IdempotencyKey::class)
                ->name('telegram.send');
            Route::post('telegram/broadcast', [TelegramController::class, 'broadcast'])
                ->middleware(IdempotencyKey::class)
                ->name('telegram.broadcast');
            Route::get('telegram/bot', [TelegramController::class, 'bot'])->name('telegram.bot');
        });

        /*
        | Outbound webhooks (docs/08 §5)
        */
        Route::middleware(ApiKeyScope::class.':reports:read')->group(function (): void {
            Route::get('webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
            Route::post('webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
            Route::get('webhooks/{webhook}', [WebhookController::class, 'show'])->name('webhooks.show');
            Route::patch('webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
            Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
        });
    });
