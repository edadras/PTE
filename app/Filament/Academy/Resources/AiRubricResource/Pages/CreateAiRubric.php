<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\AiRubricResource\Pages;

use App\Domain\AI\Models\AiRubric;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Academy\Resources\AiRubricResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateAiRubric extends CreateRecord
{
    protected static string $resource = AiRubricResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['academy_id'] = TenantContext::id();
        $data['version'] = 1 + (int) AiRubric::query()
            ->where('academy_id', TenantContext::id())
            ->where('task_key', (string) $data['task_key'])
            ->max('version');

        return $data;
    }
}
