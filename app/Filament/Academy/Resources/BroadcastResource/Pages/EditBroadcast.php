<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\BroadcastResource\Pages;

use App\Filament\Academy\Resources\BroadcastResource;
use Filament\Resources\Pages\EditRecord;

final class EditBroadcast extends EditRecord
{
    protected static string $resource = BroadcastResource::class;
}
