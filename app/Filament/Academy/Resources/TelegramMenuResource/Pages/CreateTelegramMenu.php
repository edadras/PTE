<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\TelegramMenuResource\Pages;

use App\Domain\Telegram\Enums\PublishStatus;
use App\Filament\Academy\Resources\TelegramMenuResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateTelegramMenu extends CreateRecord
{
    protected static string $resource = TelegramMenuResource::class;

    /**
     * A new menu is always a draft; only PublishMenu may flip it live.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = PublishStatus::Draft;
        $data['is_active'] = false;
        $data['version'] = 1;

        return $data;
    }
}
