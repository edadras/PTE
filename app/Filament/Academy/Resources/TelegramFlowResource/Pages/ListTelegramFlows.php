<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\TelegramFlowResource\Pages;

use App\Filament\Academy\Resources\TelegramFlowResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListTelegramFlows extends ListRecords
{
    protected static string $resource = TelegramFlowResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
