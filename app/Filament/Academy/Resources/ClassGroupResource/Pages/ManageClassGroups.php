<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\ClassGroupResource\Pages;

use App\Filament\Academy\Resources\ClassGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

final class ManageClassGroups extends ManageRecords
{
    protected static string $resource = ClassGroupResource::class;

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
