<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Learning\Jobs\ImportQuestionsJob;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * `POST /api/v1/questions/bulk-import` — docs/08 §3.
 *
 * The file is stored on the tenant disk and handed to the existing import job;
 * the response is 202 plus the batch id the caller polls with.
 */
final class QuestionImportController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,json', 'max:10240'],
            'bank_id' => ['required', 'integer', 'exists:question_banks,id'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $bank = QuestionBank::query()->findOrFail((int) $validated['bank_id']);

        $path = $request->file('file')?->store('imports/questions', 'tenant');
        $batchId = (string) Str::uuid();

        ImportQuestionsJob::dispatch(
            TenantContext::id(),
            (string) $path,
            (int) $bank->getKey(),
            $batchId,
            ['dry_run' => (bool) ($validated['dry_run'] ?? false)],
        );

        return $this->payload($request, [
            'batch_id' => $batchId,
            'bank_id' => (int) $bank->getKey(),
            'status' => 'queued',
        ], status: 202);
    }
}
