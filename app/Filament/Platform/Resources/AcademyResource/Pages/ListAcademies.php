<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\AcademyResource\Pages;

use App\Filament\Platform\Resources\AcademyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListAcademies extends ListRecords
{
    protected static string $resource = AcademyResource::class;

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
