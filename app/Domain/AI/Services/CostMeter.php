<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\Data\AiCompletionRequest;
use App\Domain\AI\Data\AiCompletionResponse;
use App\Domain\AI\Data\TranscriptionRequest;
use App\Domain\AI\Data\TranscriptionResult;
use App\Domain\AI\Enums\AiRequestStatus;
use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Models\AiLog;
use App\Domain\AI\Models\AiModel;
use App\Domain\AI\Models\AiRequest;
use App\Domain\Commerce\Services\QuotaGuard;
use App\Domain\Shared\Support\TenantKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * The ledger. Every provider call lands here — successes, timeouts, refusals,
 * cache hits — because a call we did not record is money we cannot explain at
 * the end of the month (docs/06 §7.1).
 *
 * Two things are deliberately separated: ai_requests is small, permanent and
 * safe to report on; ai_logs holds the full prompt for seven days and is the
 * only place student text is ever persisted outside the Assessment context.
 */
final class CostMeter
{
    public const METRIC_REQUESTS = 'ai_requests';

    public const METRIC_TOKENS = 'ai_tokens';

    /** Commerce meters this metric in whole US cents (UsageMetric::unit()). */
    public const METRIC_COST_USD = 'ai_cost_usd';

    public const METRIC_ASR_MINUTES = 'asr_minutes';

    public function record(
        AiCompletionRequest $request,
        ?AiCompletionResponse $response,
        AiRequestStatus $status,
        ?AiModel $model = null,
        bool $fallbackUsed = false,
        ?string $errorCode = null,
        bool $byok = false,
        ?string $rawResponse = null,
    ): ?AiRequest {
        if ($request->academyId === null) {
            return null;
        }

        $model ??= AiModel::findByKey($request->modelKey);

        $promptTokens = $response?->promptTokens ?? 0;
        $completionTokens = $response?->completionTokens ?? 0;
        $cost = $model instanceof AiModel ? $model->costFor($promptTokens, $completionTokens) : 0.0;

        // A cache hit costs nothing but must still appear in the ledger, or the
        // saving it represents is invisible.
        if ($response?->cacheHit === true) {
            $cost = 0.0;
        }

        $aiRequest = $this->persist([
            'academy_id' => $request->academyId,
            'student_id' => $request->studentId,
            'answer_id' => $request->answerId,
            'task_key' => $request->task->value,
            'provider' => $request->provider->value,
            'model_key' => $request->modelKey,
            'prompt_version' => $request->promptVersion,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'cost_usd' => $cost,
            'latency_ms' => $response?->latencyMs ?? 0,
            'status' => $status->value,
            'cache_hit' => $response?->cacheHit ?? false,
            'fallback_used' => $fallbackUsed,
            'byok' => $byok,
            'error_code' => $errorCode,
            'request_id' => $response?->requestId ?? (string) Str::uuid(),
        ]);

        $this->writeDebugLog($aiRequest, $request->renderedForLog(), $rawResponse ?? $response?->text);

        if (! $byok) {
            $this->meter($request->academyId, $promptTokens + $completionTokens, $cost, 0.0);
        }

        return $aiRequest;
    }

    public function recordTranscription(
        TranscriptionRequest $request,
        ?TranscriptionResult $result,
        AiRequestStatus $status,
        ?AiModel $model = null,
        ?string $errorCode = null,
        bool $byok = false,
    ): ?AiRequest {
        if ($request->academyId === null) {
            return null;
        }

        $model ??= AiModel::findByKey($request->modelKey);

        $minutes = round(($result?->durationSeconds ?? $request->durationSeconds) / 60.0, 4);
        $cost = $model instanceof AiModel ? $model->costForAudioMinutes($minutes) : 0.0;

        $aiRequest = $this->persist([
            'academy_id' => $request->academyId,
            'answer_id' => $request->answerId,
            'task_key' => AiTaskKey::Transcription->value,
            'provider' => $request->provider->value,
            'model_key' => $request->modelKey,
            'audio_minutes' => $minutes,
            'cost_usd' => $cost,
            'latency_ms' => $result?->latencyMs ?? 0,
            'status' => $status->value,
            'error_code' => $errorCode,
            'byok' => $byok,
            'request_id' => (string) Str::uuid(),
        ]);

        if (! $byok) {
            $this->meter($request->academyId, 0, $cost, $minutes);
        }

        return $aiRequest;
    }

    public function costFor(AiModel $model, int $promptTokens, int $completionTokens): float
    {
        return $model->costFor($promptTokens, $completionTokens);
    }

    /**
     * Ask the Commerce layer whether there is budget left. Fails *open* when the
     * quota system is absent or errors: refusing to score because the meter is
     * broken punishes the customer for our outage.
     */
    public function hasQuota(int $academyId, string $metric = self::METRIC_REQUESTS, int $amount = 1): bool
    {
        $guard = $this->guard($academyId);

        if ($guard === null) {
            return true;
        }

        try {
            $result = $guard->check($metric, $amount);

            return ! method_exists($result, 'allowed') || $result->allowed();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persist(array $attributes): AiRequest
    {
        $request = new AiRequest;
        $request->forceFill($attributes + ['created_at' => now()]);
        $request->save();

        return $request;
    }

    private function writeDebugLog(AiRequest $request, string $prompt, ?string $rawResponse): void
    {
        if ((int) config('pte.retention.ai_logs', 7) <= 0) {
            return;
        }

        $log = new AiLog;
        $log->forceFill([
            'academy_id' => $request->academy_id,
            'ai_request_id' => $request->getKey(),
            'rendered_prompt' => $prompt,
            'raw_response' => $rawResponse,
            'created_at' => now(),
        ]);
        $log->save();
    }

    /**
     * Push usage into the tenant counters. QuotaGuard is built by the Commerce
     * context in parallel, so the call is discovered at runtime; if it is not
     * there yet the counters still move, in Redis, under the same key.
     */
    private function meter(int $academyId, int $tokens, float $costUsd, float $audioMinutes): void
    {
        $increments = array_filter([
            self::METRIC_REQUESTS => 1,
            self::METRIC_TOKENS => $tokens,
            self::METRIC_COST_USD => $this->centsWithCarry($academyId, $costUsd),
            self::METRIC_ASR_MINUTES => (int) ceil($audioMinutes),
        ], static fn (int $amount): bool => $amount > 0);

        foreach ($increments as $metric => $amount) {
            $this->increment($academyId, $metric, $amount);
        }
    }

    /**
     * Commerce counts cost in whole cents, but a Flash call costs a fraction of
     * one. Rounding each call would either inflate the meter by 100x or lose
     * the spend entirely, so the sub-cent remainder is carried forward and only
     * whole cents are reported.
     */
    private function centsWithCarry(int $academyId, float $costUsd): int
    {
        if ($costUsd <= 0.0) {
            return 0;
        }

        $key = TenantKey::for($academyId, 'ai:cost-carry', now()->format('Y-m'));
        $pending = (float) Cache::get($key, 0.0) + ($costUsd * 100);
        $cents = (int) floor($pending);

        Cache::put($key, round($pending - $cents, 6), 40 * 86400);

        return $cents;
    }

    private function increment(int $academyId, string $metric, int $amount): void
    {
        $guard = $this->guard($academyId);

        if ($guard !== null) {
            try {
                foreach (['consume', 'increment', 'record'] as $method) {
                    if (method_exists($guard, $method)) {
                        $guard->{$method}($metric, $amount);

                        return;
                    }
                }

                $guard->check($metric, $amount);

                return;
            } catch (Throwable) {
                // Fall through to the local counter rather than lose the usage.
            }
        }

        $key = TenantKey::quota($academyId, $metric, now()->format('Y-m'));

        if (! Cache::add($key, $amount, 40 * 86400)) {
            Cache::increment($key, $amount);
        }
    }

    /**
     * QuotaGuard belongs to the Commerce context and is resolved at runtime, so
     * the AI layer keeps working in a deployment where billing is not installed.
     */
    private function guard(int $academyId): ?object
    {
        if (! class_exists(QuotaGuard::class)) {
            return null;
        }

        try {
            return method_exists(QuotaGuard::class, 'forAcademy')
                ? QuotaGuard::forAcademy($academyId)
                : app(QuotaGuard::class);
        } catch (Throwable) {
            return null;
        }
    }
}
