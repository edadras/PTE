<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\AiRubricResource\Pages;

use App\Filament\Academy\Resources\AiRubricResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

final class EditAiRubric extends EditRecord
{
    protected static string $resource = AiRubricResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
