<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\TelegramFlowResource\Pages;

use App\Filament\Academy\Resources\TelegramFlowResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateTelegramFlow extends CreateRecord
{
    protected static string $resource = TelegramFlowResource::class;

    protected function getRedirectUrl(): string
    {
        // Straight into the editor: a flow is only useful once it has edges.
        return TelegramFlowResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
