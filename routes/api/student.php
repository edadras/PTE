<?php

declare(strict_types=1);

use App\Domain\Integration\Support\ApiBootstrap;
use App\Http\Controllers\Api\Student\AuthController;
use App\Http\Controllers\Api\Student\BrandController;
use App\Http\Controllers\Api\Student\ExamController;
use App\Http\Controllers\Api\Student\MeController;
use App\Http\Controllers\Api\Student\MediaUploadController;
use App\Http\Controllers\Api\Student\ModuleController;
use App\Http\Controllers\Api\Student\PracticeController;
use App\Http\Controllers\Api\Student\ProgressController;
use App\Http\Controllers\Api\Student\SupportTicketController;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\StudentTokenGuard;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Student API — /api/student/v1
|--------------------------------------------------------------------------
|
| The tenant comes from the Host header, never from the token. That is the whole
| point of docs/08 §2: a token carries `aid` for the client's convenience and
| the server ignores it, re-resolving the academy and rejecting a mismatch.
|
| @see docs/08-api-and-integrations.md §3
*/

ApiBootstrap::register();

Route::prefix('student/v1')
    ->name('api.student.')
    ->middleware([ForceJsonResponse::class, 'tenant.domain'])
    ->group(function (): void {
        /*
        | Public — the web app needs the brand before anyone has logged in.
        */
        Route::get('brand', BrandController::class)
            ->middleware('throttle:student-api')
            ->name('brand');

        Route::middleware('throttle:login')->group(function (): void {
            Route::post('auth/telegram', [AuthController::class, 'telegram'])->name('auth.telegram');
            Route::post('auth/otp', [AuthController::class, 'otp'])->name('auth.otp');
        });

        /*
        | Authenticated
        */
        Route::middleware([StudentTokenGuard::class, 'throttle:student-api'])->group(function (): void {
            Route::get('me', [MeController::class, 'show'])->name('me.show');
            Route::patch('me', [MeController::class, 'update'])->name('me.update');

            Route::get('modules', ModuleController::class)->name('modules');

            Route::post('practice/start', [PracticeController::class, 'start'])
                ->middleware(IdempotencyKey::class)
                ->name('practice.start');
            Route::get('practice/{session}', [PracticeController::class, 'show'])->name('practice.show');
            Route::post('practice/{session}/answer', [PracticeController::class, 'answer'])
                ->name('practice.answer');
            Route::post('practice/{session}/finish', [PracticeController::class, 'finish'])
                ->name('practice.finish');

            Route::get('exams', [ExamController::class, 'index'])->name('exams.index');
            Route::post('exams/{exam}/start', [ExamController::class, 'start'])
                ->middleware(IdempotencyKey::class)
                ->name('exams.start');
            Route::get('exam-sessions/{session}', [ExamController::class, 'session'])
                ->name('exam-sessions.show');
            Route::post('exam-sessions/{session}/answer', [ExamController::class, 'answer'])
                ->name('exam-sessions.answer');
            Route::post('exam-sessions/{session}/submit', [ExamController::class, 'submit'])
                ->name('exam-sessions.submit');

            Route::get('scores', [ProgressController::class, 'scores'])->name('scores');
            Route::get('progress', [ProgressController::class, 'progress'])->name('progress');

            Route::post('support/tickets', [SupportTicketController::class, 'store'])
                ->middleware(IdempotencyKey::class)
                ->name('support.tickets.store');
        });

        Route::post('media/upload', MediaUploadController::class)
            ->middleware([StudentTokenGuard::class, 'throttle:media-upload'])
            ->name('media.upload');
    });
