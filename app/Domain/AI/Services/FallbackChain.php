<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\Data\AiCompletionRequest;
use App\Domain\AI\Data\AiCompletionResponse;
use App\Domain\AI\Data\ResolvedModel;
use App\Domain\AI\Enums\AiRequestStatus;
use App\Domain\AI\Exceptions\InvalidAiResponseException;
use App\Domain\AI\Exceptions\ProviderUnavailableException;
use App\Domain\AI\Models\AiModel;
use Illuminate\Support\Facades\Log;

/**
 * Walks the model chain until one of them answers acceptably.
 *
 * The two failure modes are handled differently, which is the whole point:
 * a provider that is down is skipped immediately, while a provider that
 * answered badly gets exactly one more attempt before we move on — models are
 * stochastic and a second sample is usually cheaper than a bigger model.
 *
 * Every attempt is metered, including the ones that failed: the tokens were
 * spent either way (docs/06 §7.1, §8).
 */
final class FallbackChain
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderResolver $resolver,
        private readonly ResponseValidator $validator,
        private readonly CircuitBreaker $breaker,
        private readonly CostMeter $meter,
    ) {}

    public function run(ResolvedModel $resolved, AiCompletionRequest $request): AiCompletionResponse
    {
        $models = $this->resolver->chainModels($resolved);

        if ($models === []) {
            $models = [$resolved->model];
        }

        $attempts = 0;
        $maxRetries = (int) config('pte.ai.max_retries', 1);

        foreach ($models as $position => $model) {
            if (! $this->breaker->allows($model->provider, $model->model_key)) {
                continue;
            }

            $attemptRequest = $request->withModel($model->provider, $model->model_key);
            $retries = 0;

            while (true) {
                $attempts++;

                try {
                    $response = $this->attempt($attemptRequest, $model, $position > 0, $resolved);

                    return $response;
                } catch (ProviderUnavailableException $e) {
                    $this->breaker->recordFailure($model->provider, $model->model_key);
                    $this->meterFailure($attemptRequest, $model, $resolved, $this->statusFor($e), $e->errorCode, $position > 0);

                    // The provider itself is the problem; another sample from it
                    // will not help.
                    break;
                } catch (InvalidAiResponseException $e) {
                    Log::warning('AI response failed validation', [
                        'task' => $request->task->value,
                        'model' => $model->model_key,
                        'violations' => $e->violations,
                        'academy_id' => $request->academyId,
                    ]);

                    if ($retries < $maxRetries) {
                        $retries++;

                        continue;
                    }

                    break;
                }
            }
        }

        throw ProviderUnavailableException::chainExhausted($request->task->value, $attempts);
    }

    private function attempt(
        AiCompletionRequest $request,
        AiModel $model,
        bool $isFallback,
        ResolvedModel $resolved,
    ): AiCompletionResponse {
        $client = $this->registry->completionClient($model->provider);

        $response = $client->complete($request);

        try {
            $validated = $this->validator->validate($response, $request->outputSchema);
        } catch (InvalidAiResponseException $e) {
            // Malformed output still consumed tokens.
            $this->meter->record(
                request: $request,
                response: $response,
                status: AiRequestStatus::Failed,
                model: $model,
                fallbackUsed: $isFallback,
                errorCode: 'invalid_response',
                byok: $resolved->usesOwnKey,
                rawResponse: $response->text,
            );

            throw $e;
        }

        $this->breaker->recordSuccess($model->provider, $model->model_key);

        $aiRequest = $this->meter->record(
            request: $request,
            response: $validated,
            status: AiRequestStatus::Success,
            model: $model,
            fallbackUsed: $isFallback,
            byok: $resolved->usesOwnKey,
            rawResponse: $validated->text,
        );

        $validated = $validated->withFallbackUsed($isFallback);

        return $aiRequest === null ? $validated : $validated->withAiRequestId((int) $aiRequest->getKey());
    }

    private function meterFailure(
        AiCompletionRequest $request,
        AiModel $model,
        ResolvedModel $resolved,
        AiRequestStatus $status,
        ?string $errorCode,
        bool $isFallback,
    ): void {
        $this->meter->record(
            request: $request,
            response: null,
            status: $status,
            model: $model,
            fallbackUsed: $isFallback,
            errorCode: $errorCode,
            byok: $resolved->usesOwnKey,
        );
    }

    private function statusFor(ProviderUnavailableException $e): AiRequestStatus
    {
        return match (true) {
            $e->rateLimited => AiRequestStatus::RateLimited,
            $e->timedOut => AiRequestStatus::Timeout,
            default => AiRequestStatus::Failed,
        };
    }
}
