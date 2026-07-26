<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\ModuleResource\Pages;

use App\Filament\Platform\Resources\ModuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

final class ManageModules extends ManageRecords
{
    protected static string $resource = ModuleResource::class;

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
