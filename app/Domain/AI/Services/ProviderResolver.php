<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\Data\ResolvedModel;
use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Exceptions\ProviderUnavailableException;
use App\Domain\AI\Models\AcademyAiSetting;
use App\Domain\AI\Models\AiModel;

/**
 * Answers "which model does this academy's next call hit, and on whose key".
 *
 * Order of precedence:
 *   1. the academy's academy_ai_settings row for the task,
 *   2. the platform default flagged on ai_models.is_default_for,
 *   3. failure — never a silently different model.
 *
 * @see docs/06-ai-layer.md §2
 */
final class ProviderResolver
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    /**
     * @param  bool  $preferCheap  economy routing: practice runs on the cheap model, exams on the strong one (docs/06 §7.3)
     */
    public function resolve(AiTaskKey $task, ?int $academyId = null, bool $preferCheap = false): ResolvedModel
    {
        $setting = $academyId === null ? null : $this->settingFor($task, $academyId);

        $model = $setting !== null
            ? AiModel::findByKey($setting->model_key)
            : null;

        if ($model === null || ! $model->is_active) {
            $model = AiModel::defaultFor($task);
        }

        if (! $model instanceof AiModel) {
            throw ProviderUnavailableException::noModelAvailable($task->value);
        }

        $economy = ($setting?->economy_mode ?? false) || $preferCheap;

        if ($economy) {
            $model = $model->economyAlternative() ?? $model;
        }

        return new ResolvedModel(
            task: $task,
            provider: $model->provider,
            modelKey: $model->model_key,
            model: $model,
            temperature: $this->temperature($setting),
            maxOutputTokens: $this->maxOutputTokens($setting),
            fallbackChain: $this->chain($task, $model, $setting),
            usesOwnKey: $setting?->usesByok() ?? false,
            apiKey: $setting?->usesByok() === true ? $setting->api_key : null,
            cacheEnabled: $setting?->cache_enabled ?? true,
            economyMode: $economy,
        );
    }

    public function resolveTranscription(?int $academyId = null): ResolvedModel
    {
        return $this->resolve(AiTaskKey::Transcription, $academyId);
    }

    /**
     * The chain as concrete models, filtered to those we can actually reach.
     *
     * @return array<int, AiModel>
     */
    public function chainModels(ResolvedModel $resolved): array
    {
        $models = [];

        foreach ($resolved->fallbackChain as $modelKey) {
            $model = AiModel::findByKey($modelKey);

            if (! $model instanceof AiModel || ! $model->is_active) {
                continue;
            }

            // A BYOK key belongs to one vendor; the chain may not silently
            // spend the platform's money on a different one.
            if ($resolved->usesOwnKey && $model->provider !== $resolved->provider) {
                continue;
            }

            if (! $resolved->usesOwnKey && blank(config("pte.ai.providers.{$model->provider->configKey()}.api_key"))) {
                continue;
            }

            if (! $this->registry->supports($model->provider, $model->model_key)) {
                continue;
            }

            $models[$model->model_key] = $model;
        }

        return array_values($models);
    }

    public function settingFor(AiTaskKey $task, int $academyId): ?AcademyAiSetting
    {
        return AcademyAiSetting::query()
            ->forAcademy($academyId)
            ->where('task_key', $task->value)
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function chain(AiTaskKey $task, AiModel $model, ?AcademyAiSetting $setting): array
    {
        $chain = [$model->model_key];

        foreach ($setting?->fallback_chain ?? [] as $modelKey) {
            $chain[] = (string) $modelKey;
        }

        // Without a configured chain, fall back to the platform default for the
        // task and then to the cheapest active model of the same vendor — one
        // degraded score beats no score.
        if (count($chain) === 1) {
            $platformDefault = AiModel::defaultFor($task);

            if ($platformDefault instanceof AiModel) {
                $chain[] = $platformDefault->model_key;
            }

            $economy = $model->economyAlternative();

            if ($economy instanceof AiModel) {
                $chain[] = $economy->model_key;
            }
        }

        return array_values(array_unique($chain));
    }

    private function temperature(?AcademyAiSetting $setting): float
    {
        return $setting?->temperature ?? (float) config('pte.ai.default_temperature', 0.3);
    }

    private function maxOutputTokens(?AcademyAiSetting $setting): int
    {
        return $setting?->max_output_tokens ?? (int) config('pte.ai.default_max_output_tokens', 1200);
    }
}
