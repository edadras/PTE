<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\StudentResource\Pages;

use App\Filament\Academy\Resources\StudentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListStudents extends ListRecords
{
    protected static string $resource = StudentResource::class;

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
