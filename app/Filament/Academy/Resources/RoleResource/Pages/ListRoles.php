<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\RoleResource\Pages;

use App\Filament\Academy\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

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
