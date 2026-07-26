<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\AiPromptResource\Pages;

use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Enums\PromptStatus;
use App\Domain\AI\Models\AiPrompt;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Academy\Resources\AiPromptResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateAiPrompt extends CreateRecord
{
    protected static string $resource = AiPromptResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $task = AiTaskKey::from((string) $data['key']);

        $draft = new AiPrompt;
        $draft->academy_id = TenantContext::id();
        $draft->key = $task;

        /** @var AiPrompt $prompt */
        $prompt = AiPrompt::query()->create([
            'academy_id' => TenantContext::id(),
            'key' => $task,
            'version' => $draft->nextVersion(),
            'status' => PromptStatus::Draft,
            'system_prompt' => $data['system_prompt'] ?? null,
            'user_template' => (string) $data['user_template'],
            'output_schema' => $data['output_schema'] ?? null,
            'variables' => $task->availableVariables(),
            'model_hint' => $data['model_hint'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return $prompt;
    }
}
