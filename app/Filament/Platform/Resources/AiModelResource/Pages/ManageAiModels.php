<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\AiModelResource\Pages;

use App\Filament\Platform\Resources\AiModelResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

final class ManageAiModels extends ManageRecords
{
    protected static string $resource = AiModelResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
