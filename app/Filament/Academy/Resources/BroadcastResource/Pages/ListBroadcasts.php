<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\BroadcastResource\Pages;

use App\Filament\Academy\Resources\BroadcastResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListBroadcasts extends ListRecords
{
    protected static string $resource = BroadcastResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
