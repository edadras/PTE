<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\PlanResource\Pages;

use App\Filament\Platform\Resources\PlanResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

final class ManagePlans extends ManageRecords
{
    protected static string $resource = PlanResource::class;

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
