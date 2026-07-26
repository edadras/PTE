<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\TelegramMenuResource\Pages;

use App\Filament\Academy\Resources\TelegramMenuResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListTelegramMenus extends ListRecords
{
    protected static string $resource = TelegramMenuResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
