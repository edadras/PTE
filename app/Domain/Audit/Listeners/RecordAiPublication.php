<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\AI\Events\PromptPublished;
use App\Domain\AI\Events\RubricPublished;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use Illuminate\Events\Dispatcher;

/**
 * Mandatory audit (docs/02 §7): a prompt or rubric going live. This is what a
 * scoring dispute is settled with — *which* version was scoring students on a
 * given day.
 *
 * A platform-default row (academy_id null) belongs to no tenant trail, so it is
 * written to the platform log instead.
 */
final class RecordAiPublication
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(PromptPublished::class, [self::class, 'handlePrompt']);
        $events->listen(RubricPublished::class, [self::class, 'handleRubric']);
    }

    public function handlePrompt(PromptPublished $event): void
    {
        $prompt = $event->prompt;

        $payload = [
            'key' => $prompt->key->value,
            'version' => $prompt->version,
            'model_hint' => $prompt->model_hint,
            'tested_at' => $prompt->tested_at?->toIso8601String(),
        ];

        if ($prompt->isPlatformDefault()) {
            $this->recorder->recordPlatform(AuditAction::PromptPublished, null, $payload);

            return;
        }

        $this->recorder->record(
            AuditAction::PromptPublished,
            $prompt,
            [],
            $payload,
            (int) $prompt->academy_id,
        );
    }

    public function handleRubric(RubricPublished $event): void
    {
        $rubric = $event->rubric;

        $payload = [
            'task_key' => $rubric->task_key->value,
            'name' => $rubric->name,
            'version' => $rubric->version,
            'weights' => $rubric->weightMap(),
            'scale' => [$rubric->scale_min, $rubric->scale_max],
        ];

        if ($rubric->isPlatformDefault()) {
            $this->recorder->recordPlatform(AuditAction::RubricPublished, null, $payload);

            return;
        }

        $this->recorder->record(
            AuditAction::RubricPublished,
            $rubric,
            [],
            $payload,
            (int) $rubric->academy_id,
        );
    }
}
