<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\AiPromptResource\Pages;

use App\Filament\Academy\Resources\AiPromptResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListAiPrompts extends ListRecords
{
    protected static string $resource = AiPromptResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
